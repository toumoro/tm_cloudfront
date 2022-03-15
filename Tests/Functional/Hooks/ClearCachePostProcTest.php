<?php

/**
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 * @author Simon Ouellet
 */

namespace Toumoro\TmCloudfront\Tests\Unit\Hooks;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\ActionService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Core\Bootstrap;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Context\Context;



//class ClearCachePostProcTest extends UnitTestCase
class ClearCachePostProcTest extends  FunctionalTestCase
{
    /**
     * @var array Have styleguide loaded
     */
    protected $testExtensionsToLoad = [
        'typo3conf/ext/typo3db_legacy',
        'typo3conf/ext/tm_cloudfront',
    ];

        /**
     * Default Site Configuration
     * @var array
     */
    protected $siteLanguageConfiguration = [
        1 => [
            'title' => 'Dansk',
            'enabled' => true,
            'languageId' => 1,
            'base' => '/dk/',
            'typo3Language' => 'dk',
            'locale' => 'da_DK.UTF-8',
            'iso-639-1' => 'da',
            'flag' => 'dk',
            'fallbackType' => 'fallback',
            'fallbacks' => '0'
        ],
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
    protected function setUp(): void {
        parent::setUp();
        $this->importCSVDataSet('typo3conf/ext/tm_cloudfront/Tests/Functional/DataSet/TranslatedSubpages.csv');
        $this->setUpFrontendSite(1, $this->siteLanguageConfiguration);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['distributionIds'] = 'AAAAAAAAAAAAAAA';
         
    }
    

        /**
     * Helper function to call protected or private methods
     *
     * @param object $object The object to be invoked
     * @param string $name the name of the method to call
     * @param mixed $arguments
     * @return mixed
     */
    protected function callInaccessibleMethod($object, $name, ...$arguments)
    {
        $reflectionObject = new \ReflectionObject($object);
        $reflectionMethod = $reflectionObject->getMethod($name);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($object, $arguments);
    }
    /**
     * @test
     */

    public function generateUrl() {

        $this->setUpBackendUserFromFixture(1);

        $this->actionService = $this->getActionService();
        Bootstrap::initializeLanguageObject();

        $this->actionService->modifyRecord(
            'pages',
            5,
            ['title' => 'Testing 1']
        );


        $rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('*', 'tx_tmcloudfront_domain_model_invalidation',"pathsegment in ('/en/sub*','/dk/subtest*')");
        $this->assertEquals(count($rows),2);
          
    }

      /**
     * Create a simple site config for the tests that
     * call a frontend page.
     *
     * @param int $pageId
     * @param array $additionalLanguages
     */
    protected function setUpFrontendSite(int $pageId, array $additionalLanguages = [])
    {
        $languages = [
            0 => [
                'title' => 'English',
                'enabled' => true,
                'languageId' => 0,
                'base' => '/en/',
                'typo3Language' => 'default',
                'locale' => 'en_US.UTF-8',
                'iso-639-1' => 'en',
                'navigationTitle' => '',
                'hreflang' => '',
                'direction' => '',
                'flag' => 'us',
            ]
        ];
        $languages = array_merge($languages, $additionalLanguages);
        $configuration = [
            'rootPageId' => $pageId,
            'base' => '/',
            'languages' => $languages,
            'errorHandling' => [],
            'routes' => [],
        ];
        GeneralUtility::mkdir_deep($this->instancePath . '/typo3conf/sites/testing/');
        $yamlFileContents = Yaml::dump($configuration, 99, 2);
        $fileName = $this->instancePath . '/typo3conf/sites/testing/config.yaml';
        GeneralUtility::writeFile($fileName, $yamlFileContents);
        // Ensure that no other site configuration was cached before
        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('core');
        if ($cache->has('sites-configuration')) {
            $cache->remove('sites-configuration');
        }
    }

        /**
     * @return ActionService
     */
    protected function getActionService()
    {
        return GeneralUtility::makeInstance(
            ActionService::class
        );
    }


}
