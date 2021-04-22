<?php

/**
 *
 * This file is part of the "AWS CloudFront cache" Extension for TYPO3 CMS by Toumoro.com.
 * 
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2018 Toumoro.com (Simon Ouellet)
 *
 ***/

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
            /* If the record is not a page, enqueue only the current page */
            if ($table != 'pages') {
                $this->queueClearCache($uid_page, false);
            } else {
                /* if it's a page we enqueue the parent */
                $parentId = $pObj->getPID($table, $uid_page);
                $tsConfig = $pObj->getTCEMAIN_TSconfig($parentId);
                if (!$tsConfig['cearCache_disable']) {

                    if (is_numeric($parentId)) {
                        $parentId = intval($parentId);
                        $this->queueClearCache($parentId, true);
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
                        $this->cacheCmd($cmd);
                    }
                }
            }
        }
        $this->clearCache();
    }

    /**
     * This function handles the cache clearing buttons and clearCacheCmd tsconfig
     * @params array $params
     * @return void
     */
    protected function cacheCmd($params) {
        if (($params['cacheCmd'] == "all") || ($params['cacheCmd'] == "pages")) {
            $this->queueClearCache(0, true);
        } elseif (MathUtility::canBeInterpretedAsInteger($params['cacheCmd'])) {
            $this->queueClearCache($params['cacheCmd'], false);
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
    protected function queueClearCache($pageId, $recursive = false) {
        $wildcard = '';
        if ($recursive) {
            $wildcard = '*';
        }
        if ($pageId == 0) {
            $entry = '/';
        } else {
            $entry = $pageId;
        }

        if (MathUtility::canBeInterpretedAsInteger($entry)) {
            /* language handling */
            $databaseConnection = $this->getDatabaseConnection();
            $languages = $databaseConnection->exec_SELECTgetRows('uid', 'sys_language', 'hidden=0');

            if (count($languages) > 0) {
                $this->queue[] = $this->buildLink($entry, array('L' => 0)) . $wildcard;
                foreach ($languages as $k => $lang) {
                    $this->queue[] = $this->buildLink($entry, array('L' => $lang['uid'])) . $wildcard;
                }
            } else {
                $this->queue[] = $this->buildLink($entry) . $wildcard;
            }
        } else {
            $this->queue[] = $entry . $wildcard;
        }
        //debug($this->queue);exit;
    }

    /**
     *  Speaking url link generation
     *  @return string
     */
    protected function buildLink($pageUid, $linkArguments = array()) {

        $this->uriBuilder->setTargetPageUid($pageUid)->setArguments($linkArguments);
        $url = $this->uriBuilder->buildFrontendUri();
        $url = parse_url($url, PHP_URL_PATH);
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
        $this->queue = array_unique($this->queue);


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
        if (array_search('/*', $this->queue) !== false) {
//            $force = true;
            $this->queue = array('/*');
            $GLOBALS['TYPO3_DB']->exec_DELETEquery('tx_tmcloudfront_domain_model_invalidation', '1=1');
        }

        if (((!empty($this->cloudFrontConfiguration['mode'])) && ($this->cloudFrontConfiguration['mode'] == 'live')) || ($force)) {
            $cloudFront = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('Aws\CloudFront\CloudFrontClient', $options);
            foreach(explode(',', $this->cloudFrontConfiguration['distributionId']) as $distributionId) {
                $GLOBALS['BE_USER']->simplelog(implode(',', $this->queue) . ' [' . $distributionId . ']', "tm_cloudfront");
                $result = $cloudFront->createInvalidation([
                    'DistributionId' => $distributionId, // REQUIRED
                    'InvalidationBatch' => [// REQUIRED
                        'CallerReference' => $caller, // REQUIRED
                        'Paths' => [// REQUIRED
                            'Items' => $this->queue, // items or paths to invalidate
                            'Quantity' => count($this->queue), // REQUIRED (must be equal to the number of 'Items' in the previus line)
                        ]
                    ]
                ]);
            }
        } else {
            foreach(explode(',', $this->cloudFrontConfiguration['distributionId']) as $distributionId) {
                foreach ($this->queue as $k => $value) {
                    $data = [
                        'pathsegment' => $value,
                        'distributionId' => $distributionId
                    ];
                    $GLOBALS['TYPO3_DB']->exec_INSERTquery('tx_tmcloudfront_domain_model_invalidation', $data);
                }
            }
        }

        //$GLOBALS['BE_USER']->simplelog(implode(',', $this->queue), "tm_cloudfront");
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

}
