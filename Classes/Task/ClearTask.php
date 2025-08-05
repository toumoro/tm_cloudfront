<?php

namespace Toumoro\TmCloudfront\Task;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ClearTask extends \TYPO3\CMS\Scheduler\Task\AbstractTask
{

    protected $cloudFrontConfiguration = [];

    public function execute()
    {

        $this->cloudFrontConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront'];

        if (!$this->cloudFrontConfiguration) {
            $this->cloudFrontConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('tm_cloudfront');            
            $this->cloudFrontConfiguration = $this->cloudFrontConfiguration['cloudfront.'];
        }

        $distributionIds = $this->getAllDistributionIds();

        foreach ($distributionIds as $distId) {

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');
            //clean duplicate values
            /*            $GLOBALS['TYPO3_DB']->sql_query("DELETE FROM tx_tmcloudfront_domain_model_invalidation WHERE distributionId = '".$distId."' AND  uid NOT IN (SELECT * FROM (SELECT MAX(n.uid) FROM tx_tmcloudfront_domain_model_invalidation n where distributionId = '".$distId."' GROUP BY n.pathsegment) x)");
            $GLOBALS['TYPO3_DB']->sql_query("delete e.* FROM tx_tmcloudfront_domain_model_invalidation e WHERE e.distributionId = '".$distId."' and e.pathsegment != '/*' and 1 >= ( select id  from (select count(*) as id from tx_tmcloudfront_domain_model_invalidation as reftable WHERE reftable.distributionId = '".$distId."' and reftable.pathsegment = '/*') x)");*/
            list($row) = $queryBuilder->count('*')->from('tx_tmcloudfront_domain_model_invalidation')
                ->where(
                    $queryBuilder->expr()->eq('distributionId', $queryBuilder->createNamedParameter($distId, Connection::PARAM_STR))
                )->executeQuery()->fetchFirstColumn();

            $count = $row['t'];

            if ($count > 0) {

                $options = [
                    'version' => $this->cloudFrontConfiguration['version'],
                    'region' => $this->cloudFrontConfiguration['region'],
                    'credentials' => [
                        'key' => $this->cloudFrontConfiguration['apikey'],
                        'secret' => $this->cloudFrontConfiguration['apisecret'],
                    ]
                ];
                $cloudFront = GeneralUtility::makeInstance('Aws\CloudFront\CloudFrontClient', $options);

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
                    $rows = $queryBuilder->select('*')->from('tx_tmcloudfront_domain_model_invalidation')
                        ->where(
                            $queryBuilder->expr()->eq('distributionId', $queryBuilder->createNamedParameter($distId, Connection::PARAM_STR))

                        )
                        ->setMaxResults($availableInvalidations)
                        ->executeQuery()->fetchAllAssociative();

                    foreach ($rows as $k => $value) {

                        $GLOBALS['BE_USER']->writelog($value['pathsegment'] . ' (' . $distId . ')', "tm_cloudfront");
                        $result = $cloudFront->createInvalidation([
                            'DistributionId' => $distId, // REQUIRED
                            'InvalidationBatch' => [// REQUIRED
                                'CallerReference' => $this->generateRandomString(16), // REQUIRED
                                'Paths' => [// REQUIRED
                                    'Items' => array($value['pathsegment']), // items or paths to invalidate
                                    'Quantity' => 1, // REQUIRED (must be equal to the number of 'Items' in the previus line)
                                ]
                            ]
                        ]);
                        $queryBuilder->delete('tx_tmcloudfront_domain_model_invalidation')
                            ->where(
                                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($value['uid'], Connection::PARAM_INT))
                            )
                            ->executeStatement();
                    }
                }
            }
        }
        return true;
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


    protected function getAllDistributionIds(): array
    {
        $mapping = $this->resolveDistributionIds();
        $ids = [];
        foreach ($mapping as $distString) {
            foreach (explode(',', $distString) as $id) {
                $id = trim($id);
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }
        return array_unique($ids);
    }

}