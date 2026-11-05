<?php

declare(strict_types=1);

/*
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Toumoro\TmCloudfront\Service;

use Aws\CloudFront\CloudFrontClient;
use Toumoro\TmCloudfront\Configuration\CloudFrontConfiguration;
use Toumoro\TmCloudfront\Domain\Repository\InvalidationRepository;
use Toumoro\TmCloudfront\Log\CloudFrontLogger;

/**
 * Sends invalidations to CloudFront ("live" mode) or stores them in the database queue ("table" mode),
 * and processes that queue (scheduler task).
 */
class InvalidationDispatcher
{
    public const WILDCARD_ALL = '/*';

    private ?CloudFrontClient $client = null;

    public function __construct(
        private readonly CloudFrontConfiguration $configuration,
        private readonly InvalidationRepository $repository,
        private readonly CloudFrontLogger $logger,
    ) {}

    /**
     * Allows to inject a pre-configured client (e.g. with a mock handler in tests).
     */
    public function setClient(?CloudFrontClient $client): void
    {
        $this->client = $client;
    }

    private function getClient(): CloudFrontClient
    {
        return $this->client ??= new CloudFrontClient([
            'version' => $this->configuration->getVersion(),
            'region' => $this->configuration->getRegion(),
            'credentials' => [
                'key' => $this->configuration->getApiKey(),
                'secret' => $this->configuration->getApiSecret(),
            ],
        ]);
    }

    /**
     * @param array<string, list<string>> $pathsByDistribution distribution id => paths
     */
    public function dispatch(array $pathsByDistribution): void
    {
        foreach ($pathsByDistribution as $distributionId => $paths) {
            $distributionId = (string)$distributionId;
            $paths = $this->optimizePaths($paths);
            if ($distributionId === '' || $paths === []) {
                continue;
            }
            if ($paths === [self::WILDCARD_ALL]) {
                // Everything is invalidated: pending invalidations of this distribution are obsolete.
                $this->repository->removeByDistributionId($distributionId);
            }
            if ($this->configuration->isLiveMode()) {
                $this->createInvalidation($distributionId, $paths);
            } else {
                $this->repository->add($distributionId, $paths);
            }
        }
    }

    /**
     * Sends the queued invalidations (table mode). Rows are only removed once CloudFront accepted them.
     *
     * @return bool false if at least one distribution could not be invalidated
     */
    public function processQueue(): bool
    {
        $configuredDistributionIds = array_flip($this->configuration->getAllDistributionIdList());
        $success = true;
        foreach ($this->repository->findDistributionIds() as $distributionId) {
            if (!isset($configuredDistributionIds[$distributionId])) {
                // Not (or no longer) configured: keep the rows, as before.
                continue;
            }
            $pathsByUid = $this->repository->findPathsByDistributionId($distributionId);
            if ($pathsByUid === []) {
                continue;
            }
            if ($this->createInvalidation($distributionId, $this->optimizePaths(array_values($pathsByUid)))) {
                $this->repository->removeByUids(array_keys($pathsByUid));
            } else {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * De-duplicates paths and collapses them to a single "/*" when everything is invalidated anyway,
     * or when there are more paths than allowed in one invalidation batch.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function optimizePaths(array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths, static fn($path): bool => (string)$path !== '')));
        if (in_array(self::WILDCARD_ALL, $paths, true) || count($paths) > $this->configuration->getMaxPathsPerInvalidation()) {
            return [self::WILDCARD_ALL];
        }
        return $paths;
    }

    /**
     * @param list<string> $paths
     */
    private function createInvalidation(string $distributionId, array $paths): bool
    {
        try {
            $this->getClient()->createInvalidation([
                'DistributionId' => $distributionId,
                'InvalidationBatch' => [
                    'CallerReference' => bin2hex(random_bytes(8)),
                    'Paths' => [
                        'Items' => $paths,
                        'Quantity' => count($paths),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Could not create invalidation for distribution ID %s (%s): %s',
                $distributionId,
                implode(', ', $paths),
                $e->getMessage()
            ));
            return false;
        }
        $this->logger->info(sprintf('successful invalidation paths : %s (%s).', implode(', ', $paths), $distributionId));
        return true;
    }
}
