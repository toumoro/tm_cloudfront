<?php

namespace Toumoro\TmCloudfront\Task;

use Aws\CloudFront\CloudFrontClient;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use Doctrine\DBAL\Driver\Exception;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;

class ClearTask extends AbstractTask
{

    protected array $cloudFrontConfiguration = [];

    public function __wakeup()
    {
        $this->cloudFrontConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('tm_cloudfront')['cloudfront'];
    }

    /**
     * @return bool
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    public function execute()
    {
        $distributionIds = explode(',', implode(',', $this->resolveDistributionIds()));

        foreach ($distributionIds as $distId) {
            //clean duplicate values
            /* GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable("tx_tmcloudfront_domain_model_invalidation")
                ->prepare("delete inv from tx_tmcloudfront_domain_model_invalidation inv inner join tx_tmcloudfront_domain_model_invalidation jt on inv.pathsegment = jt.pathsegment and inv.distributionId = jt.distributionId and jt.uid > inv.uid")
            ->executeStatement(); */

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');
            $count = $queryBuilder
                ->count('uid')
                ->from('tx_tmcloudfront_domain_model_invalidation')
                ->where(
                    $queryBuilder->expr()->eq('distributionId', $queryBuilder->createNamedParameter($distId, Connection::PARAM_STR))
                )
                ->executeQuery()
                ->fetchOne();

            if ($count > 0) {

                $options = [
                    'version' => $this->cloudFrontConfiguration['version'],
                    'region' => $this->cloudFrontConfiguration['region'],
                    'credentials' => [
                        'key' => $this->cloudFrontConfiguration['apikey'],
                        'secret' => $this->cloudFrontConfiguration['apisecret'],
                    ]
                ];
                $cloudFront = GeneralUtility::makeInstance(CloudFrontClient::class, $options);

                $list = $cloudFront->listInvalidations([
                    'DistributionId' => $distId,
                    'MaxItems' => '30',
                ]);

                /* the max is 15 but we let 5 spot available for manual clear cache in the backend */
                $availableInvalidations = 10;
                $items = $list->get('InvalidationList')['Items'];
                if (!empty($items)) {
                    foreach ($items as $k => $value) {
                        if ($value["Status"] != 'Completed') {
                            $availableInvalidations--;
                        }
                    }
                }

                if ($availableInvalidations > 0) {
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');
                    $rows = $queryBuilder
                        ->select('*')
                        ->from('tx_tmcloudfront_domain_model_invalidation')
                        ->setMaxResults($availableInvalidations)->where($queryBuilder->expr()->eq('distributionId', $queryBuilder->createNamedParameter($distId, Connection::PARAM_STR)))->executeQuery()
                        ->fetchAllAssociative();

                    foreach (array_chunk($rows, $availableInvalidations) as $chunk) {
                        $this->cc($chunk, $cloudFront, $distId);
                    }
                }
            }
        }
        return true;
    }

    protected function cc($chunk,$cloudFront,$distId, $flushDb = true)
    {
        $pathsegments = [];
        $ids = [];

        foreach ($chunk as $value) {
            $pathsegments[] = $value['pathsegment'] ?? 'undefined? ';
            $ids[] = $value['uid'] ?? '-1 ';
        }

        try {
            $cloudFront->createInvalidation([
                'DistributionId' => $distId, // REQUIRED
                'InvalidationBatch' => [// REQUIRED
                    'CallerReference' => $this->generateRandomString(), // REQUIRED
                    'Paths' => [// REQUIRED
                        'Items' => $pathsegments,
                        // items or paths to invalidate
                        'Quantity' => count($pathsegments),
                        // REQUIRED (must be equal to the number of 'Items' in the previus line)
                    ]
                ]
            ]);
            $GLOBALS['BE_USER']->writelog(4, 0, 0, 0,'successful invalidation paths :' . implode(', ', $pathsegments) . ' (' . $distId . ').',"tm_cloudfront");
        } catch (\Exception $e) {
            $GLOBALS['BE_USER']->writelog(4, 0, 0, 0,'exception for invalidation paths :' . implode(', ', $pathsegments) . ' (' . $distId . ').',"tm_cloudfront");
            if (count($chunk) > 1) {
                $GLOBALS['BE_USER']->writelog(4, 0, 0, 0,'Now iterating one by one (' . $distId . ')',"tm_cloudfront");
                foreach (array_chunk($chunk, 1) as $atomic_chunk) {
                    $this->cc($atomic_chunk, $cloudFront, $distId, false);
                }
            }
        }

        if (!$flushDb) {
            return;
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');
        $queryBuilder->delete('tx_tmcloudfront_domain_model_invalidation')->where($queryBuilder->expr()->in('uid', $ids))->executeStatement();
    }

    /**
     * Generate a random string
     * @param int $length length of the string
     * @return string
     */
    protected function generateRandomString($length = 10)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
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

}