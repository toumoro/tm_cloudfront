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
    public ?CloudFrontClient $cloudFrontClient = NULL;

    public function __wakeup()
    {
        $this->setExtConf();
    }

    public function setExtConf()
    {
        $this->cloudFrontConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('tm_cloudfront')['cloudfront'];
    }

    //for testing
    public function setCloudfrontClient(CloudFrontClient $cloudFrontClient)
    {
        $this->cloudFrontClient = $cloudFrontClient;
    }
    public function getCloudfrontClient()
    {
        if ($this->cloudFrontClient === NULL) {
            $options = [
                'version' => $this->cloudFrontConfiguration['version'],
                'region' => $this->cloudFrontConfiguration['region'],
                'credentials' => [
                    'key' => $this->cloudFrontConfiguration['apikey'],
                    'secret' => $this->cloudFrontConfiguration['apisecret'],
                ]
            ];
            $this->cloudFrontClient = GeneralUtility::makeInstance(CloudFrontClient::class, $options);
        }
        return $this->cloudFrontClient;
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

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');
            $rows = $queryBuilder
                ->select('uid', 'pathsegment')
                ->from('tx_tmcloudfront_domain_model_invalidation')
                ->where(
                    $queryBuilder->expr()->eq('distributionId', $queryBuilder->createNamedParameter($distId, Connection::PARAM_STR))
                )
                ->executeQuery()
                ->fetchAllAssociative();

            if (!empty($rows)) {

                foreach ($rows as $v) {
                    $pathsegments[] = $v['pathsegment'];
                    $uids[] = $v['uid'];
                }
                $this->cc(array_unique($pathsegments), array_unique($uids), $distId);
            }
        }
        return true;
    }

    protected function cc($pathsegments, $uids, $distId)
    {

        try {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');

            $cloudFront = $this->getCloudfrontClient();
            $cloudFront->createInvalidation([
                'DistributionId' => $distId, // REQUIRED
                'InvalidationBatch' => [ // REQUIRED
                    'CallerReference' => $this->generateRandomString(), // REQUIRED
                    'Paths' => [ // REQUIRED
                        'Items' => $pathsegments,
                        // items or paths to invalidate
                        'Quantity' => count($pathsegments),
                        // REQUIRED (must be equal to the number of 'Items' in the previus line)
                    ]
                ]
            ]);
            $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, 'successful invalidation paths :' . implode(', ', $pathsegments) . ' (' . $distId . ').', ["ext" => "tm_cloudfront"]);
            $queryBuilder->delete('tx_tmcloudfront_domain_model_invalidation')->where($queryBuilder->expr()->in('uid', $uids))->executeStatement();
        } catch (\Exception $e) {
            print_r($e->getMessage());
            $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, 'exception for invalidation paths :' . implode(', ', $pathsegments) . ' (' . $distId . ').', ["ext" => "tm_cloudfront"]);
        }
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
