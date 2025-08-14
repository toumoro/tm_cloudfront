<?php

namespace Toumoro\TmCloudfront\EventListener;

use Aws\CloudFront\CloudFrontClient;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedToIndexEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileUpdatedInIndexEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class ClearCloudfrontCacheListener
{
    protected array $queue = array();
    protected array $cloudFrontConfiguration = array();

    /**
     * @var array
     */
    protected $distributionsMapping = [];

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        $this->cloudFrontConfiguration =
            GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('tm_cloudfront')['cloudfront'];
        $this->distributionsMapping = $this->resolveDistributionIds();
    }

    public function __invoke(object $event): void
    {
        if ($event instanceof AfterFileAddedToIndexEvent || $event instanceof AfterFileUpdatedInIndexEvent) {
            $file = $event->getFile();
            // Your custom logic here
            $this->fileMod($file);
        }
    }

    /**
     * Retourne le(s) distributionId(s) à utiliser selon le domaine et la config
     *
     * @return array
     */
    protected function resolveDistributionIds(): array
    {
        $mapping = $this->cloudFrontConfiguration['distributionIds'] ?? '';
        if ($mapping && is_string($mapping) && $mapping[0] === '{') {
            $mappingArray = json_decode($mapping, true);
            if (is_array($mappingArray)) {
                return $mappingArray;
            }
        }
        return explode(',', $mapping);
    }

    protected function fileMod(\TYPO3\CMS\Core\Resource\File $file): void
    {
        $storage = $file->getStorage();
        $storageConfig = $storage->getConfiguration();

        if (isset($storageConfig['domain'])) {
            $distributionIds = isset($this->distributionsMapping[$storageConfig['domain']]) 
                ? $this->distributionsMapping[$storageConfig['domain']]
                : implode(',', array_values($this->distributionsMapping));
            $this->enqueue('/' . $file->getPublicUrl(), $distributionIds);
            $this->clearCache();
        }
    }

    protected function enqueue($link, $distributionIds): void
    {
        $distArray = explode(',', $distributionIds);
        foreach ($distArray as $key => $value) {
            $this->queue[$value][] = $link;
        }
    }

    protected function clearCache()
    {

        foreach ($this->queue as $distId => $paths) {

            $paths = array_unique($paths);

            $queryBuilder = $this->connectionPool
                ->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');
            $caller = $this->generateRandomString(16);
            $options = [
                'version' => $this->cloudFrontConfiguration['version'],
                'region' => $this->cloudFrontConfiguration['region'],
                'credentials' => [
                    'key' => $this->cloudFrontConfiguration['apikey'],
                    'secret' => $this->cloudFrontConfiguration['apisecret'],
                ]
            ];

            /* force a clear all cache */
            $force = false;
            if (in_array('/*', $paths)) {
                $paths = array('/*');
                $queryBuilder->delete('tx_tmcloudfront_domain_model_invalidation')
                    ->where(
                        $queryBuilder->expr()->eq('distributionId', $queryBuilder->createNamedParameter($distId, Connection::PARAM_STR))
                    )
                    ->executeStatement();
            }

            if (((!empty($this->cloudFrontConfiguration['mode'])) && ($this->cloudFrontConfiguration['mode'] == 'live')) || ($force)) {
                $cloudFront = GeneralUtility::makeInstance(CloudFrontClient::class, $options);
                $GLOBALS['BE_USER']->writelog(implode(',', $this->queue), "tm_cloudfront");
                try {
                    $result = $cloudFront->createInvalidation([
                        'DistributionId' => $distId, // REQUIRED
                        'InvalidationBatch' => [// REQUIRED
                            'CallerReference' => $caller, // REQUIRED
                            'Paths' => [// REQUIRED
                                'Items' => $paths, // items or paths to invalidate
                                'Quantity' => count($paths), // REQUIRED (must be equal to the number of 'Items' in the previus line)
                            ]
                        ]
                    ]);
                } catch (\Exception $e) {

                }
            } else {
                foreach ($paths as $k => $value) {
                    $data = [
                        'pathsegment' => $value,
                        'distributionId' => $distId,
                    ];
                    $queryBuilder->insert('tx_tmcloudfront_domain_model_invalidation')->values($data)
                    ->executeStatement();
                }
            }
        }
    }

    protected function generateRandomString($length = 10): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}
