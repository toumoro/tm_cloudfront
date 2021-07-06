<?php

/**
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 * @author Simon Ouellet
 */

namespace Toumoro\TmCloudfront\Hooks;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class ClearCachePostProc {

    protected $configurationManager;
    protected $objectManager;
    protected $contentObjectRenderer;
    protected $uriBuilder;
    protected $queue = array();
    protected $cloudFrontConfiguration = array();

    /**
     * Constructs this object.
     * @return void
     */
    public function __construct() {

        /* Retrieve extension configuration */
        $this->cloudFrontConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront'];
        if (!$this->cloudFrontConfiguration) {
                $this->cloudFrontConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['tm_cloudfront']);
                $this->cloudFrontConfiguration = $this->cloudFrontConfiguration['cloudfront.'];
        }
        
        $this->initTsfe();
    }
    
    public function getCfDistributionIds() {
        
        $this->initTsfe();
    }

    /**
     * Initialize tsfe for speaking url link creation
     * @return void
     */
    protected function initTsfe() {
        /* init tsfe */

        if (empty($GLOBALS['TSFE'])) {
            $GLOBALS['TSFE'] = GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Controller\\TypoScriptFrontendController', $GLOBALS['TYPO3_CONF_VARS'], $id, $type);
            $GLOBALS['TSFE']->connectToDB();
            $GLOBALS['TSFE']->initFEuser();
            $GLOBALS['TSFE']->determineId();
            $GLOBALS['TSFE']->initTemplate();
            $GLOBALS['TSFE']->getConfigArray();
        }
        $this->objectManager = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\Extbase\\Object\\ObjectManager');
        $this->configurationManager = $this->objectManager->get(ConfigurationManager::class);
        /** @var ContentObjectRenderer $contentObjectRenderer */
        $this->contentObjectRenderer = $this->objectManager->get(ContentObjectRenderer::class);
        $this->configurationManager->setContentObject($this->contentObjectRenderer);
        $this->uriBuilder = $this->objectManager->get(UriBuilder::class);
        $this->uriBuilder->injectConfigurationManager($this->configurationManager);
    }

    /**
     * Clear cache post processor.
     * The same structure as DataHandler::clear_cache
     *
     * @param    array       $params : parameter array
     * @param    DataHandler $pObj   : partent object
     *
     * @return    void
     */
    public function clearCachePostProc(&$params, &$pObj) {

        $pathToClear = array();
        $pageUids = array();

        if ($pObj->BE_USER->workspace > 0) {
            // Do nothing when editor is inside a workspace
            return;
        }

        if (isset($params['cacheCmd'])) {
            /* when a clear cache button is clicked */
            $this->cacheCmd($params);
        } else {
            
            /* When a record is saved */
            $uid = intval($params['uid']);
            $table = strval($params['table']);
            $uid_page = intval($params['uid_page']);
            
            /* if it's a page we enqueue the parent */
            $parentId = $pObj->getPID($table, $uid_page);
            $tsConfig = $pObj->getTCEMAIN_TSconfig($parentId);
            
            //get the distributionId for the root page, null means all (defined in extconf)
            $distributionIds = null;
            if (!empty($tsConfig['distributionIds'])) {
                $distributionIds = $tsConfig['distributionIds'];
            }

            /* If the record is not a page, enqueue only the current page */
            if ($table != 'pages') {
                $this->queueClearCache($uid_page, false,$distributionIds);
            } else {
                

                

                if (!$tsConfig['cearCache_disable']) {

                    if (is_numeric($parentId)) {
                        $parentId = intval($parentId);
                        $this->queueClearCache($parentId, true,$distributionIds);
                    } else {
                        // pid has no valid value: value is no integer or value is a negative integer (-1)
                        return;
                    }
                }
                // Clear cache for pages entered in TSconfig:
                if ($tsConfig['clearCacheCmd']) {
                    $Commands = GeneralUtility::trimExplode(',', strtolower($tsConfig['clearCacheCmd']), TRUE);
                    $Commands = array_unique($Commands);
                    foreach ($Commands as $cmdPart) {
                        $cmd = array('cacheCmd' => $cmdPart);
                        $this->cacheCmd($cmd,$distributionIds);
                    }
                }
            }
        }
        $this->clearCache();
    }

    /**
     * This function handles the cache clearing buttons and clearCacheCmd tsconfig
     * @param array $params
     * @param array $distributionIds comma seperated list of distributions ids, NULL means all (defined in the extension configuration)
     * @return void
     */
    protected function cacheCmd($params,$distributionIds = null) {
        if (($params['cacheCmd'] == "all") || ($params['cacheCmd'] == "pages")) {
            $this->queueClearCache(0, true,$distributionIds);
        } elseif (MathUtility::canBeInterpretedAsInteger($params['cacheCmd'])) {
            $this->queueClearCache($params['cacheCmd'], false,$distributionIds);
        }
    }

    /**
     * Enqueue cache entries in $this->queue
     * A cache entry will be added to the queue for each language of the website.
     * For exemple:
     * If you clear the contact page, /contact/, /en/contact and 
     * /fr/contact will be cleared depending on your speaking url configuration.
     * 
     * @param int $pageId entry to clear. 0 means all cache "/"
     * @param bool $recursive recursive entry clearing
     */
    protected function queueClearCache($pageId, $recursive = false,$distributionIds = null) {
        $wildcard = '';
        if ($recursive) {
            $wildcard = '*';
        }
        if ($pageId == 0) {
            $entry = '/';
        } else {
            $entry = $pageId;
        }
        
        
        if ($distributionIds === null) {
            $distributionIds = $this->cloudFrontConfiguration['distributionIds'];
        }


        if (MathUtility::canBeInterpretedAsInteger($entry)) {
            /* language handling */
            $databaseConnection = $this->getDatabaseConnection();
            $languages = $databaseConnection->exec_SELECTgetRows('uid', 'sys_language', 'hidden=0');
            
            if (count($languages) > 0) {
                $this->enqueue($this->buildLink($entry, array('L' => 0)) . $wildcard,$distributionIds);
                foreach ($languages as $k => $lang) {
                    $this->enqueue($this->buildLink($entry, array('L' => $lang['uid'])) . $wildcard,$distributionIds);
                }
            } else {
                $this->enqueue($this->buildLink($entry) . $wildcard,$distributionIds);
            }
        } else {
            $this->enqueue($entry . $wildcard,$distributionIds);
        }
        //debug($this->queue);exit;
    }
    
    protected function enqueue($link,$distributionIds) {
        $distArray = explode(',',$distributionIds);
        foreach($distArray as $key => $value) {
            $this->queue[$value][] = $link;
        }
    }

    /**
     *  Speaking url link generation
     *  @return string
     */
    protected function buildLink($pageUid, $linkArguments = array()) {

        //some record saving function might raise a tsfe inialisation error
        try {
            $this->uriBuilder->setTargetPageUid($pageUid)->setArguments($linkArguments);
            $url = $this->uriBuilder->buildFrontendUri();
            $url = parse_url($url, PHP_URL_PATH);
        } catch (\TypeError $e) {
        }
        if (empty($url)) {
            //possible if the parent page is exluded from path.
            $url = '/';
        }
        return $url;
    }

    /**
     * This function sends a Cloudfront invalidate query based on the cache queue ($this->queue).
     * @return void
     */
    protected function clearCache() {

        foreach ($this->queue as $distId => $paths) {

            $paths = array_unique($paths);
            
    
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
            if (array_search('/*', $paths) !== false) {
                $paths = array('/*');
                $GLOBALS['TYPO3_DB']->exec_DELETEquery('tx_tmcloudfront_domain_model_invalidation', "distributionId = '".$distId."'");
            }
    
            if (((!empty($this->cloudFrontConfiguration['mode'])) && ($this->cloudFrontConfiguration['mode'] == 'live')) || ($force)) {
                $cloudFront = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('Aws\CloudFront\CloudFrontClient', $options);
                $GLOBALS['BE_USER']->simplelog(implode(',', $this->queue), "tm_cloudfront");
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
                } catch(\Exception $e) {
                    
                }
            } else {
                foreach ($paths as $k => $value) {
                    $data = [
                        'pathsegment' => $value,
                        'distributionId' => $distId,
                    ];
                    $GLOBALS['TYPO3_DB']->exec_INSERTquery('tx_tmcloudfront_domain_model_invalidation', $data);
                }
            }
        }
    }

    /**
     * Get database connection
     *
     * @return DatabaseConnection
     */
    protected function getDatabaseConnection() {
        return $GLOBALS['TYPO3_DB'];
    }

    /**
     * Generate a random string
     * @param int $length length of the string
     * @return string
     */
    protected function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public function fileMod($file) {
        if (!empty($this->cloudFrontConfiguration['fileStorage'])) {
            foreach ($this->cloudFrontConfiguration['fileStorage'] as $storage => $distributionIds) {
                if ($file->getStorage()->getUid() == $storage) {
                    $this->enqueue('/'.$file->getPublicUrl(),$distributionIds);
                    $this->clearCache();
                }
            }
        }

    }

}
