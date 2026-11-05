<?php

/**
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 * @author Simon Ouellet
 */

namespace Toumoro\TmCloudfront\Tests\Functional\Hooks;


use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Aws\MockHandler;
use Aws\Result;
use Aws\CloudFront\CloudFrontClient;
use Toumoro\TmCloudfront\Service\InvalidationDispatcher;
use Toumoro\TmCloudfront\Task\ClearTask;

class ClearTaskTest extends FunctionalTestCase
{
    /**
     * @var array Have styleguide loaded
     */
    protected array $testExtensionsToLoad = [
        'toumoro/tm-cloudfront',
        'typo3/cms-scheduler'
    ];

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

        $mockHandler = new MockHandler([
            new Result(['Invalidation' => ['Id' => 'ID1234567890']]),
            new Result(['Invalidation' => ['Id' => 'ID1234567891']]),
            new Result(['Invalidation' => ['Id' => 'ID1234567892']]),
        ]);
        $this->get(InvalidationDispatcher::class)->setClient(new CloudFrontClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'AAAAAAAAAAAAAAA', 'secret' => 'AAAAAAAAAAAAAAA'],
            'handler' => $mockHandler,
        ]));

        $task = GeneralUtility::makeInstance(ClearTask::class);
        $this->assertTrue($task->execute());
        // One invalidation per distribution
        $this->assertCount(0, $mockHandler, 'Une invalidation par distribution attendue');

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');
        $rows = $queryBuilder
            ->select('uid', 'pathsegment')
            ->from('tx_tmcloudfront_domain_model_invalidation')
            ->executeQuery()
            ->fetchAllAssociative();
        $this->assertCount(0, $rows, 'Nombre d’invalidation incorrect');
    }
}
