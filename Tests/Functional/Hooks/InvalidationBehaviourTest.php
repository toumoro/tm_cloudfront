<?php

declare(strict_types=1);

namespace Toumoro\TmCloudfront\Tests\Functional\Hooks;

use Aws\CloudFront\CloudFrontClient;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Toumoro\TmCloudfront\Service\InvalidationDispatcher;
use Toumoro\TmCloudfront\Task\ClearTask;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Page cache commands, distribution id formats, TCEMAIN.clearCache_disable and path batches.
 *
 * Pages (see DataSet/translated_subpages.csv): 3 "/sub" (dk "/subtest", es "/subtest"), 5 "/sub/sub"
 * (dk "/sub/subd", es "/sub/subes") below 3, 7 sysfolder. Content element 1 is on page 5.
 */
final class InvalidationBehaviourTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'toumoro/tm-cloudfront',
        'typo3/cms-scheduler',
    ];

    private const TABLE = 'tx_tmcloudfront_domain_model_invalidation';

    /**
     * Paths of page 5 in every language
     */
    private const PAGE_5_PATHS = ['/dk/sub/subd', '/en/sub/sub', '/es/sub/subes'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../DataSet/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../DataSet/translated_subpages.csv');
        $this->importCSVDataSet(__DIR__ . '/../DataSet/tt_content.csv');
        $this->setUpBackendUser(1);

        $languages = Yaml::parseFile(__DIR__ . '/../Fixtures/languages.yaml');
        GeneralUtility::mkdir_deep($this->instancePath . '/typo3conf/sites/testing/');
        GeneralUtility::writeFile(
            $this->instancePath . '/typo3conf/sites/testing/config.yaml',
            Yaml::dump(['rootPageId' => 1, 'base' => 'https://www.example.com/', 'languages' => $languages['simple']], 99, 2)
        );
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');

        // Globals are not reset between tests
        $this->setSettings([]);
    }

    // 1. Page cache command (page cache button, TCEMAIN.clearCacheCmd = <uid>)

    #[Test]
    public function pageCacheCommandInvalidatesThePagePathInEveryLanguageForTheMappedDistribution(): void
    {
        $this->getDataHandler()->clear_cacheCmd('5');

        self::assertSame($this->expectedRows(self::PAGE_5_PATHS, ['WWWWWWWWW']), $this->getInvalidations());
    }

    #[Test]
    public function pageCacheCommandUsesAllDistributionsWhenNoDomainOfTheSiteIsMapped(): void
    {
        $this->setSettings(['distributionIds' => '{"other.example.com":"OTHEROTHER","cdn.example.com":"CDNCDNCDNCDN"}']);

        $this->getDataHandler()->clear_cacheCmd('5');

        self::assertSame($this->expectedRows(self::PAGE_5_PATHS, ['CDNCDNCDNCDN', 'OTHEROTHER']), $this->getInvalidations());
    }

    #[Test]
    public function pageCacheCommandUsesNoDistributionWhenTheDomainIsDisabled(): void
    {
        $this->setSettings(['distributionIds' => '{"www.example.com":"","cdn.example.com":"CDNCDNCDNCDN"}']);

        $this->getDataHandler()->clear_cacheCmd('5');

        self::assertSame([], $this->getInvalidations());
    }

    #[Test]
    public function pageCacheCommandIgnoresSysFolders(): void
    {
        $this->getDataHandler()->clear_cacheCmd('7');

        self::assertSame([], $this->getInvalidations());
    }

    // 2. Plain list of distribution ids and ids with spaces

    #[Test]
    public function plainListOfDistributionIdsInvalidatesEveryDistribution(): void
    {
        $this->setSettings(['distributionIds' => 'AAAAAAAAA, BBBBBBBBB']);

        $this->modifyContentElement();

        self::assertSame($this->expectedRows(self::PAGE_5_PATHS, ['AAAAAAAAA', 'BBBBBBBBB']), $this->getInvalidations());
    }

    #[Test]
    public function pageCacheCommandWithAPlainListOfDistributionIdsInvalidatesEveryDistribution(): void
    {
        $this->setSettings(['distributionIds' => 'AAAAAAAAA,BBBBBBBBB']);

        $this->getDataHandler()->clear_cacheCmd('5');

        self::assertSame($this->expectedRows(self::PAGE_5_PATHS, ['AAAAAAAAA', 'BBBBBBBBB']), $this->getInvalidations());
    }

    #[Test]
    public function distributionIdsWithSpacesInTheMappingAreTrimmed(): void
    {
        $this->setSettings(['distributionIds' => '{"www.example.com":" AAAAAAAAA ,  BBBBBBBBB "}']);

        $this->modifyContentElement();

        self::assertSame($this->expectedRows(self::PAGE_5_PATHS, ['AAAAAAAAA', 'BBBBBBBBB']), $this->getInvalidations());
    }

    #[Test]
    public function taskSendsTheQueueOfDistributionIdsConfiguredWithSpaces(): void
    {
        $this->setSettings(['distributionIds' => '{"www.example.com":"AAAAAAAAA, BBBBBBBBB"}']);
        $this->queue([['/a', 'AAAAAAAAA'], ['/b', 'BBBBBBBBB']]);
        $mockHandler = $this->mockCloudFront(2);

        self::assertTrue(GeneralUtility::makeInstance(ClearTask::class)->execute());

        self::assertCount(0, $mockHandler, 'One invalidation per distribution, " BBBBBBBBB" included');
        self::assertSame([], $this->getInvalidations());
    }

    // 3. TCEMAIN.clearCache_disable

    #[Test]
    public function clearCacheDisableStopsTheInvalidationOfContentElements(): void
    {
        $this->setPageTsConfig(3, 'TCEMAIN.clearCache_disable = 1');

        $this->modifyContentElement();

        self::assertSame([], $this->getInvalidations());
    }

    #[Test]
    public function clearCacheDisableStopsTheInvalidationOfPages(): void
    {
        $this->setPageTsConfig(3, 'TCEMAIN.clearCache_disable = 1');

        $this->modifyRecord('pages', 5, ['title' => 'Modified']);

        self::assertSame([], $this->getInvalidations());
    }

    // 5. Path batches

    #[Test]
    public function morePathsThanMaxInvalidationPathsAreCollapsedToAGlobalInvalidation(): void
    {
        $this->setSettings(['maxInvalidationPaths' => 2]);

        $this->modifyContentElement();

        self::assertSame([['/*', 'WWWWWWWWW']], $this->getInvalidations());
    }

    #[Test]
    public function globalInvalidationReplacesTheQueuedPathsOfEveryDistribution(): void
    {
        $this->setSettings(['distributionIds' => '{"www.example.com":"WWWWWWWWW","cdn.example.com":"CDNCDNCDNCDN"}']);
        $this->queue([['/old', 'WWWWWWWWW'], ['/older', 'WWWWWWWWW'], ['/file.pdf', 'CDNCDNCDNCDN']]);

        $this->getDataHandler()->clear_cacheCmd('pages');

        self::assertSame([['/*', 'CDNCDNCDNCDN'], ['/*', 'WWWWWWWWW']], $this->getInvalidations());
    }

    #[Test]
    public function taskSendsOnlyTheGlobalInvalidationWhenItIsQueued(): void
    {
        $this->queue([['/a', 'WWWWWWWWW'], ['/*', 'WWWWWWWWW'], ['/b', 'WWWWWWWWW']]);
        $mockHandler = $this->mockCloudFront(1);

        GeneralUtility::makeInstance(ClearTask::class)->execute();

        self::assertSame(['/*'], $this->getSentPaths($mockHandler));
        self::assertSame([], $this->getInvalidations());
    }

    #[Test]
    public function taskCollapsesMoreQueuedPathsThanMaxInvalidationPaths(): void
    {
        $this->setSettings(['maxInvalidationPaths' => 2]);
        $this->queue([['/a', 'WWWWWWWWW'], ['/b', 'WWWWWWWWW'], ['/c', 'WWWWWWWWW']]);
        $mockHandler = $this->mockCloudFront(1);

        GeneralUtility::makeInstance(ClearTask::class)->execute();

        self::assertSame(['/*'], $this->getSentPaths($mockHandler));
    }

    #[Test]
    public function taskSendsTheQueuedPathsWithoutDuplicates(): void
    {
        $this->queue([['/a', 'WWWWWWWWW'], ['/b', 'WWWWWWWWW']]);
        // Same path queued again: not stored twice
        $this->modifyContentElement();
        $this->modifyContentElement();
        $mockHandler = $this->mockCloudFront(1);

        GeneralUtility::makeInstance(ClearTask::class)->execute();

        $sent = $this->getSentPaths($mockHandler);
        sort($sent);
        self::assertSame(['/a', '/b', ...self::PAGE_5_PATHS], $sent);
    }

    private function setSettings(array $settings): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront'] = array_replace([
            'distributionIds' => '{"www.example.com":"WWWWWWWWW"}',
            'mode' => 'table',
            'region' => 'us-east-1',
            'apikey' => 'AAAAAAAAAAAAAAA',
            'apisecret' => 'AAAAAAAAAAAAAAA',
            'version' => 'latest',
        ], $settings);
    }

    private function setPageTsConfig(int $pageId, string $tsConfig): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['TSconfig' => $tsConfig], ['uid' => $pageId]);
    }

    private function modifyContentElement(): void
    {
        $this->modifyRecord('tt_content', 1, ['header' => 'Modified ' . uniqid()]);
    }

    private function modifyRecord(string $table, int $uid, array $values): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $values]], []);
        $dataHandler->process_datamap();
    }

    private function getDataHandler(): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);
        return $dataHandler;
    }

    /**
     * @param list<array{0: string, 1: string}> $rows [path, distribution id]
     */
    private function queue(array $rows): void
    {
        foreach ($rows as [$path, $distributionId]) {
            $this->getConnectionPool()->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
                'pathsegment' => $path,
                'distributionId' => $distributionId,
                'id' => md5($path . $distributionId),
            ]);
        }
    }

    private function mockCloudFront(int $expectedCalls): MockHandler
    {
        $mockHandler = new MockHandler(array_fill(0, $expectedCalls, new Result(['Invalidation' => ['Id' => 'ID']])));
        $this->get(InvalidationDispatcher::class)->setClient(new CloudFrontClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'AAAAAAAAAAAAAAA', 'secret' => 'AAAAAAAAAAAAAAA'],
            'handler' => $mockHandler,
        ]));
        return $mockHandler;
    }

    /**
     * @return list<string>
     */
    private function getSentPaths(MockHandler $mockHandler): array
    {
        return $mockHandler->getLastCommand()->toArray()['InvalidationBatch']['Paths']['Items'];
    }

    /**
     * @param list<string> $paths
     * @param list<string> $distributionIds
     * @return list<array{0: string, 1: string}>
     */
    private function expectedRows(array $paths, array $distributionIds): array
    {
        $rows = [];
        foreach ($paths as $path) {
            foreach ($distributionIds as $distributionId) {
                $rows[] = [$path, $distributionId];
            }
        }
        sort($rows);
        return $rows;
    }

    /**
     * @return list<array{0: string, 1: string}> [path, distribution id], sorted
     */
    private function getInvalidations(): array
    {
        $rows = array_map(
            static fn(array $row): array => [$row['pathsegment'], $row['distributionId']],
            $this->getAllRecords(self::TABLE)
        );
        sort($rows);
        return $rows;
    }
}
