<?php

declare(strict_types=1);

namespace Toumoro\TmCloudfront\Tests\Functional\Cache;

use Aws\CloudFront\CloudFrontClient;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Toumoro\TmCloudfront\Middleware\CacheTagHeaderMiddleware;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use Symfony\Component\Yaml\Yaml;
use Toumoro\TmCloudfront\Service\InvalidationDispatcher;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Record cache clearing with CloudFront tag invalidation.
 */
final class CacheTagInvalidationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'toumoro/tm-cloudfront',
        'typo3/cms-scheduler',
    ];

    private const TABLE = 'tx_tmcloudfront_domain_model_invalidation';

    private const SETTINGS = [
        'distributionIds' => '{"www.example.com":"WWWWWWWWW","cdn.example.com":"CDNCDNCDNCDN","es.example.com":""}',
        'mode' => 'table',
        'region' => 'us-east-1',
        'apikey' => 'AAAAAAAAAAAAAAA',
        'apisecret' => 'AAAAAAAAAAAAAAA',
        'version' => 'latest',
        'cacheTags' => 'tx_news_domain_model_news, tx_news_pid',
    ];

    /**
     * Written to the settings file: frontend sub requests reload the configuration from it.
     */
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => ['tm_cloudfront' => ['cloudfront' => self::SETTINGS]],
    ];

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
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront'] = self::SETTINGS;

    }

    #[Test]
    public function responseContainsTheConfiguredCacheTags(): void
    {
        $this->setUpFrontendRootPage(1, ['EXT:tm_cloudfront/Tests/Functional/Fixtures/Frontend/page.typoscript']);

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://www.example.com/en/sub/sub'));

        self::assertSame(200, $response->getStatusCode());
        // pageId_*, pages_* and unrelated tags are not sent
        self::assertSame('tx_news_domain_model_news_1, tx_news_pid_12', $response->getHeaderLine('x-amz-meta-cache-tag'));
    }

    #[Test]
    public function customTagsFromPageStdWrapAreSentAndInvalidated(): void
    {
        $this->setUpFrontendRootPage(1, ['EXT:tm_cloudfront/Tests/Functional/Fixtures/Frontend/page-stdwrap.typoscript']);
        $this->writeSettingsForFrontend(['cacheTags' => 'my_custom_tag, another_tag']);

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://www.example.com/en/sub/sub'));
        self::assertSame('my_custom_tag, another_tag', $response->getHeaderLine('x-amz-meta-cache-tag'));

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront']['cacheTags'] = 'my_custom_tag, another_tag';
        $this->getDataHandler()->clear_cacheCmd('cacheTag:my_custom_tag');
        self::assertSame(['#my_custom_tag'], array_values(array_unique(array_column($this->getInvalidations(), 0))));
    }

    #[Test]
    public function recordUidTagFromTypoScriptOnARoutedDetailView(): void
    {
        $languages = Yaml::parseFile(__DIR__ . '/../Fixtures/languages.yaml');
        GeneralUtility::writeFile(
            $this->instancePath . '/typo3conf/sites/testing/config.yaml',
            Yaml::dump([
                'rootPageId' => 1,
                'base' => 'https://www.example.com/',
                'languages' => $languages['simple'],
                'routeEnhancers' => [
                    'MyExtItem' => [
                        'type' => 'Simple',
                        'limitToPages' => [5],
                        'routePath' => '/item/{item}',
                        'requirements' => ['item' => '\\d+'],
                        '_arguments' => ['item' => 'tx_myext_item/item'],
                        'aspects' => ['item' => ['type' => 'StaticRangeMapper', 'start' => '1', 'end' => '1000']],
                    ],
                ],
            ], 99, 2)
        );
        $this->get(CacheManager::class)->getCache('core')->remove('sites-configuration');
        $this->setUpFrontendRootPage(1, ['EXT:tm_cloudfront/Tests/Functional/Fixtures/Frontend/page-record-tag.typoscript']);
        $this->writeSettingsForFrontend(['cacheTags' => 'tx_myext_domain_model_item']);

        $detail = $this->executeFrontendSubRequest(new InternalRequest('https://www.example.com/en/sub/sub/item/12'));
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame('tx_myext_domain_model_item_12', $detail->getHeaderLine('x-amz-meta-cache-tag'));

        $page = $this->executeFrontendSubRequest(new InternalRequest('https://www.example.com/en/sub/sub'));
        self::assertFalse($page->hasHeader('x-amz-meta-cache-tag'), 'No tag outside the detail view');
    }

    #[Test]
    public function headerNameIsConfigurable(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront']['cacheTagHeader'] = 'x-cache-tags';

        $response = $this->processMiddleware('tx_news_domain_model_news_1', 'pageId_5');

        self::assertSame('tx_news_domain_model_news_1', $response->getHeaderLine('x-cache-tags'));
        self::assertFalse($response->hasHeader('x-amz-meta-cache-tag'));
    }

    #[Test]
    public function noHeaderWhenNoCacheTagIsConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront']['cacheTags'] = '';

        $response = $this->processMiddleware('tx_news_domain_model_news_1');

        self::assertFalse($response->hasHeader('x-amz-meta-cache-tag'));
    }

    #[Test]
    public function headerIsLimitedTo50Tags(): void
    {
        $response = $this->processMiddleware(...array_map(static fn(int $i): string => 'tx_news_domain_model_news_' . $i, range(1, 60)));

        self::assertCount(50, explode(', ', $response->getHeaderLine('x-amz-meta-cache-tag')));
    }

    #[Test]
    public function recordTagsCoveredByTheirTableTagAreDroppedAboveTheLimit(): void
    {
        // e.g. a list rendering 60 news with frontend.cache.autoTagging, the table tag added last
        $tags = array_map(static fn(int $i): string => 'tx_news_domain_model_news_' . $i, range(1, 60));
        $tags[] = 'tx_news_pid_12';
        $tags[] = 'tx_news_domain_model_news';

        $response = $this->processMiddleware(...$tags);

        self::assertSame('tx_news_pid_12, tx_news_domain_model_news', $response->getHeaderLine('x-amz-meta-cache-tag'));
    }

    #[Test]
    public function configuredTagsAreKeptFirstWhenTheLimitIsStillExceeded(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront']['cacheTags'] = 'tx_news_uid, tx_news_pid';
        // 60 record tags without their table tag, then a tag equal to a "cacheTags" entry
        $tags = array_map(static fn(int $i): string => 'tx_news_uid_' . $i, range(1, 60));
        $tags[] = 'tx_news_pid';

        $sent = explode(', ', $this->processMiddleware(...$tags)->getHeaderLine('x-amz-meta-cache-tag'));

        self::assertCount(50, $sent);
        self::assertSame('tx_news_pid', $sent[0]);
    }

    #[Test]
    public function tagsUnderTheLimitAreSentUnchanged(): void
    {
        $response = $this->processMiddleware('tx_news_domain_model_news', 'tx_news_domain_model_news_1', 'tx_news_pid_12');

        self::assertSame(
            'tx_news_domain_model_news, tx_news_domain_model_news_1, tx_news_pid_12',
            $response->getHeaderLine('x-amz-meta-cache-tag')
        );
    }

    #[Test]
    public function cacheTagCommandInvalidatesTheTagOnEveryDistribution(): void
    {
        $this->getDataHandler()->clear_cacheCmd('cacheTag:tx_news_domain_model_news_1');

        $invalidations = $this->getInvalidations();
        sort($invalidations);
        self::assertSame([
            ['#tx_news_domain_model_news_1', 'CDNCDNCDNCDN'],
            ['#tx_news_domain_model_news_1', 'WWWWWWWWW'],
        ], $invalidations);
    }

    #[Test]
    public function onlyConfiguredTagsAreInvalidated(): void
    {
        $this->get(CacheManager::class)->flushCachesInGroupByTags('pages', [
            'tx_news_domain_model_news',
            'tx_news_domain_model_news_2',
            'tx_news_pid_12',
            'tx_news_domain_model_newsletter_1',
            'pageId_5',
            'pages_5',
            'tt_content_1',
        ]);

        $paths = array_values(array_unique(array_column($this->getInvalidations(), 0)));
        sort($paths);
        self::assertSame(['#tx_news_domain_model_news', '#tx_news_domain_model_news_2', '#tx_news_pid_12'], $paths);
    }

    #[Test]
    public function savingARecordOfAConfiguredTableInvalidatesItsTags(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront']['cacheTags'] = 'tt_content';

        $dataHandler = $this->getDataHandler();
        $dataHandler->start(['tt_content' => [1 => ['header' => 'Modified']]], []);
        $dataHandler->process_datamap();

        $paths = array_column($this->getInvalidations(), 0);
        // Page path (hook) + record tags
        self::assertContains('/en/sub/sub', $paths);
        self::assertContains('#tt_content_1', $paths);
        self::assertContains('#tt_content', $paths);
    }

    #[Test]
    public function noTagInvalidationWhenNoCacheTagIsConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront']['cacheTags'] = '';

        $this->getDataHandler()->clear_cacheCmd('cacheTag:tx_news_domain_model_news_1');

        self::assertSame([], $this->getInvalidations());
    }

    #[Test]
    public function liveModeSendsTagItemsToCloudFront(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront']['mode'] = 'live';
        $mockHandler = new MockHandler([
            new Result(['Invalidation' => ['Id' => 'ID1']]),
            new Result(['Invalidation' => ['Id' => 'ID2']]),
        ]);
        $this->get(InvalidationDispatcher::class)->setClient(new CloudFrontClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'AAAAAAAAAAAAAAA', 'secret' => 'AAAAAAAAAAAAAAA'],
            'handler' => $mockHandler,
        ]));

        $this->get(CacheManager::class)->flushCachesInGroupByTags('pages', ['tx_news_domain_model_news_1']);

        self::assertCount(0, $mockHandler, 'One invalidation per distribution');
        $batch = $mockHandler->getLastCommand()->toArray()['InvalidationBatch'];
        self::assertSame(['#tx_news_domain_model_news_1'], $batch['Paths']['Items']);
        self::assertSame([], $this->getInvalidations(), 'Nothing queued in live mode');
    }

    private function processMiddleware(string ...$tags): ResponseInterface
    {
        $collector = new CacheDataCollector();
        $collector->addCacheTags(...array_map(static fn(string $tag): CacheTag => new CacheTag($tag), $tags));
        $request = (new ServerRequest('https://www.example.com/'))->withAttribute('frontend.cache.collector', $collector);
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
        return $this->get(CacheTagHeaderMiddleware::class)->process($request, $handler);
    }

    /**
     * Frontend sub requests reload the configuration from the settings file.
     */
    private function writeSettingsForFrontend(array $settings): void
    {
        $this->get(\TYPO3\CMS\Core\Configuration\ConfigurationManager::class)->setLocalConfigurationValueByPath(
            'EXTENSIONS/tm_cloudfront/cloudfront',
            array_replace(self::SETTINGS, $settings)
        );
    }

    private function getDataHandler(): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);
        return $dataHandler;
    }

    /**
     * @return list<array{0: string, 1: string}> [path, distribution id]
     */
    private function getInvalidations(): array
    {
        return array_map(
            static fn(array $row): array => [$row['pathsegment'], $row['distributionId']],
            $this->getAllRecords(self::TABLE)
        );
    }
}
