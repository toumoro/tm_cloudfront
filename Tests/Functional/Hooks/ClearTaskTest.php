<?php

/**
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 * @author Simon Ouellet
 */

namespace Toumoro\TmCloudfront\Tests\Unit\Hooks;


use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\ActionService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Aws\MockHandler;
use Aws\Result;
use Aws\CloudFront\CloudFrontClient;

class ClearTaskTest extends FunctionalTestCase
{
    /**
     * @var array Have styleguide loaded
     */
    protected array $testExtensionsToLoad = [
        'toumoro/tm-cloudfront',
        'typo3/cms-scheduler'
    ];
    protected ?array $cloudFrontConfiguration;

    /**
     * +-----+-----+-------------------+------------------+-------------+----------+
     * | uid | pid | title             | sys_language_uid | l10n_parent | slug     |
     * +-----+-----+-------------------+------------------+-------------+----------+
     * |   1 |   0 | Startpage         |                0 |           0 | /        |
     * |   2 |   0 | Startpage - Dansk |                1 |           1 | /        |
     * |   3 |   1 | Subpage           |                0 |           0 | /sub     |
     * |   4 |   1 | Subpage - Dansk   |                1 |           3 | /subtest |
     * |   5 |   3 | Testing 1         |                0 |           0 | /sub/sub |
     * |   6 |   3 | Sub Subpage       |                1 |           5 | /sub/sub |
     * +-----+-----+-------------------+------------------+-------------+----------+
     **/
    protected function setUp(): void
    {
        parent::setUp();


        $this->importCSVDataSet(__DIR__ . '/../DataSet/be_users.csv');
        $this->setUpBackendUser(1);

        $this->importCSVDataSet(__DIR__ . '/../DataSet/tx_tmcloudfront_domain_model_invalidation.csv');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront'] = [
            'distributionIds' => '{"www.example.com":"WWWWWWWWW","en.example.com":"ENENENENENEN","cdn.example.com":"CDNCDNCDNCDN","dk.example.com":"DKDKDKDKDKDK"}',
            'mode' => 'table',
            'region' => 'us',
            'apikey' => 'AAAAAAAAAAAAAAA',
            'apisecret' => 'AAAAAAAAAAAAAAA',
            'version' => 'latest',
        ];
    }


    /**
     * Test simple génération d’URL
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function executeTask(): void
    {

        $this->cloudFrontConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('tm_cloudfront')['cloudfront'];
        $taskClass = GeneralUtility::makeInstance(\Toumoro\TmCloudfront\Task\ClearTask::class);
        $taskClass->__wakeup();
        $options = [
            'version' => $this->cloudFrontConfiguration['version'],
            'region' => $this->cloudFrontConfiguration['region'],
            'credentials' => [
                'key' => $this->cloudFrontConfiguration['apikey'],
                'secret' => $this->cloudFrontConfiguration['apisecret'],
            ],
            'handler' => new MockHandler([
                new Result(['Invalidation' => ['Id' => 'ID1234567890']]),
                new Result(['Invalidation' => ['Id' => 'ID1234567891']]),
                new Result(['Invalidation' => ['Id' => 'ID1234567892']]),
                new Result(['Invalidation' => ['Id' => 'ID1234567893']]),
                new Result(['Invalidation' => ['Id' => 'ID1234567894']]),
                new Result(['Invalidation' => ['Id' => 'ID1234567895']]),
            ]),
        ];
        $taskClass->setCloudfrontClient(GeneralUtility::makeInstance(CloudFrontClient::class, $options));
        $taskClass->execute();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');
        $rows = $queryBuilder
            ->select('uid', 'pathsegment')
            ->from('tx_tmcloudfront_domain_model_invalidation')
            ->executeQuery()
            ->fetchAllAssociative();
        $this->assertCount(0, $rows, 'Nombre d’invalidation incorrect');
    }
}
