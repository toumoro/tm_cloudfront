<?php

declare(strict_types=1);

/*
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Toumoro\TmCloudfront\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Database queue of pending invalidations ("table" mode), processed by the scheduler task.
 */
class InvalidationRepository
{
    public const TABLE = 'tx_tmcloudfront_domain_model_invalidation';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Queues paths for a distribution, skipping the ones already queued.
     *
     * @param list<string> $paths
     */
    public function add(string $distributionId, array $paths): void
    {
        $idsByPath = [];
        foreach ($paths as $path) {
            $idsByPath[$path] = self::buildId($path, $distributionId);
        }
        if ($idsByPath === []) {
            return;
        }

        $existingIds = [];
        foreach (array_chunk(array_values($idsByPath), 500) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $found = $queryBuilder
                ->select('id')
                ->from(self::TABLE)
                ->where(
                    $queryBuilder->expr()->in('id', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_STR_ARRAY))
                )
                ->executeQuery()
                ->fetchFirstColumn();
            $existingIds += array_flip($found);
        }

        $now = (int)($GLOBALS['EXEC_TIME'] ?? time());
        $rows = [];
        foreach ($idsByPath as $path => $id) {
            if (!isset($existingIds[$id])) {
                $rows[] = [(string)$path, $distributionId, $id, $now, $now];
            }
        }
        if ($rows !== []) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->bulkInsert(
                self::TABLE,
                $rows,
                ['pathsegment', 'distributionId', 'id', 'tstamp', 'crdate']
            );
        }
    }

    /**
     * @return list<string>
     */
    public function findDistributionIds(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return array_map('strval', $queryBuilder
            ->select('distributionId')
            ->from(self::TABLE)
            ->groupBy('distributionId')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * @return array<int, string> uid => path
     */
    public function findPathsByDistributionId(string $distributionId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $result = $queryBuilder
            ->select('uid', 'pathsegment')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('distributionId', $queryBuilder->createNamedParameter($distributionId))
            )
            ->orderBy('uid')
            ->executeQuery();
        $paths = [];
        while ($row = $result->fetchAssociative()) {
            $paths[(int)$row['uid']] = (string)$row['pathsegment'];
        }
        return $paths;
    }

    public function removeByDistributionId(string $distributionId): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['distributionId' => $distributionId]);
    }

    /**
     * @param list<int> $uids
     */
    public function removeByUids(array $uids): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach (array_chunk($uids, 1000) as $chunk) {
            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder
                ->delete(self::TABLE)
                ->where(
                    $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY))
                )
                ->executeStatement();
        }
    }

    public static function buildId(string $path, string $distributionId): string
    {
        return md5($path . $distributionId);
    }
}
