<?php

/**
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 * @author Simon Ouellet
 */

namespace Toumoro\TmCloudfront\Hooks;

/***
 *
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2022 Toumoro
 *
 ***/

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class ClearCachePostProc
{
    /**
     * @var \TYPO3\CMS\Core\Configuration\ConfigurationManager
     */
    protected $configurationManager;

    protected $objectManager;

    /**
     * @var ContentObjectRenderer
     */
    protected $contentObjectRenderer;

    /**
     * @var UriBuilder|mixed|object
     */
    protected $uriBuilder;

    /**
     * @var array
     */
    protected $queue = [];

    /**
     * @var array
     */
    protected $cloudFrontConfiguration = [];

    /**
     * Inject UriBuilder
     * @param UriBuilder $uriBuilder
     */
    public function injectUriBuilder(UriBuilder $uriBuilder)
    {
        $this->uriBuilder = $uriBuilder;
    }

    /**
     * Constructs this object.
     * @return void
     */
    public function __construct()
    {
        /* Retrieve extension configuration */
        $this->cloudFrontConfiguration =
            GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('tm_cloudfront')['cloudfront'];
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
    public function clearCachePostProc(&$params, &$pObj)
    {
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
            $tsConfig =  BackendUtility::getPagesTSconfig($parentId);

            //get the distributionId for the root page, null means all (defined in extconf)
            $distributionIds = null;
            if (!empty($tsConfig['distributionIds'])) {
                $distributionIds = $tsConfig['distributionIds'];
            }

            /* If the record is not a page, enqueue only the current page */
            if ($table != 'pages') {
                $this->queueClearCache($uid_page, false, $distributionIds);
            } else {

                if (!$tsConfig['clearCache_disable']) {

                    if (is_numeric($parentId)) {
                        $parentId = intval($parentId);
                        $this->queueClearCache($parentId, true, $distributionIds);
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
                        $this->cacheCmd($cmd, $distributionIds);
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
    protected function cacheCmd($params, $distributionIds = null)
    {
        if (($params['cacheCmd'] == "all") || ($params['cacheCmd'] == "pages")) {
            $this->queueClearCache(0, true, $distributionIds);
        } elseif (MathUtility::canBeInterpretedAsInteger($params['cacheCmd'])) {
            $this->queueClearCache($params['cacheCmd'], false, $distributionIds);
        }
    }

    /**
     *
     * Enqueue cache entries in $this->queue
     * A cache entry will be added to the queue for each language of the website.
     * For exemple:
     * If you clear the contact page, /contact/, /en/contact and
     * /fr/contact will be cleared depending on your speaking url configuration.
     *
     *
     * @param int $pageId entry to clear. 0 means all cache "/"
     * @param bool $recursive recursive entry clearing
     * @param $distributionIds
     *
     * @return void
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws SiteNotFoundException
     */
    protected function queueClearCache($pageId, $recursive = false, $distributionIds = null)
    {
        $wildcard = '';
        if ($recursive) {
            $wildcard = '/*';
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
            $languages = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageId)->getAllLanguages();

            if (count($languages) > 0) {
                $this->enqueue($this->buildLink($entry, array('_language' => 0)) . $wildcard, $distributionIds);
                foreach ($languages as $k => $lang) {
                    if($lang->getLanguageId() != 0) {
                        $this->enqueue($this->buildLink($entry, array('_language' => $lang->getLanguageId())) . $wildcard, $distributionIds);
                    }
                }
            } else {
                $this->enqueue($this->buildLink($entry) . $wildcard, $distributionIds);
            }
        } else {
            $this->enqueue($entry . $wildcard, $distributionIds);
        }
    }

    protected function enqueue($link, $distributionIds)
    {
        $distArray = explode(',', $distributionIds);
        // for index.php link style
        if (substr($link, 0, 1) != '/') {
            $link = '/' . $link;
        }

        foreach ($distArray as $key => $value) {
            $this->queue[$value][] = $link;
        }
    }

    /**
     * Speaking url link generation
     *
     * @param $pageUid
     * @param $linkArguments
     *
     * @return array|false|int|string|null
     * @throws SiteNotFoundException
     */
    protected function buildLink($pageUid, $linkArguments = array())
    {
        //some record saving function might raise a tsfe inialisation error
        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageUid);
            $url = (string)$site->getRouter()->generateUri((string)$pageUid, $linkArguments);
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
     *
     * @return void
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    protected function clearCache()
    {
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
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');
                $affectedRows = $queryBuilder
                    ->delete('tx_tmcloudfront_domain_model_invalidation')
                    ->where(
                        $queryBuilder->expr()->eq('distributionId', $queryBuilder->createNamedParameter($distId))
                    )
                    ->execute();
            }

            if (((!empty($this->cloudFrontConfiguration['mode'])) && ($this->cloudFrontConfiguration['mode'] == 'live')) || ($force)) {
                $cloudFront = GeneralUtility::makeInstance('Aws\CloudFront\CloudFrontClient', $options);
                $GLOBALS['BE_USER']->writelog(4,0,0,0,implode(',', $paths). ' ('.$distId.')', "tm_cloudfront");
                try {
                    $result = $cloudFront->createInvalidation([
                        'DistributionId' => $distId, // REQUIRED
                        'InvalidationBatch' => [ // REQUIRED
                            'CallerReference' => $caller, // REQUIRED
                            'Paths' => [ // REQUIRED
                                'Items' => $paths, // items or paths to invalidate
                                'Quantity' => count($paths), // REQUIRED (must be equal to the number of 'Items' in the previus line)
                            ]
                        ]
                    ]);
                } catch (\Exception $e) {
                }
            } else {
                foreach ($paths as $k => $value) {
                    $data = [
                        'pathsegment' => $value,
                        'distributionId' => $distId,
                        'id' => md5($value.$distId),
                    ];
                    GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_tmcloudfront_domain_model_invalidation')
                        ->prepare("insert ignore into tx_tmcloudfront_domain_model_invalidation (pathsegment,distributionId,id) values(?,?,?)")
                        ->executeStatement($data);
                }
            }
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
     * @param $file_or_folder
     *
     * @return void
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function fileMod($file_or_folder)
    {
        if (!empty($this->cloudFrontConfiguration['fileStorage'])) {
            foreach ($this->cloudFrontConfiguration['fileStorage'] as $storage => $distributionIds) {
                if ($file_or_folder->getStorage()->getUid() == $storage) {
                    $this->enqueue('/' . $file_or_folder->getPublicUrl(), $distributionIds);
                    $this->clearCache();
                }
            }
        }
    }

    /**
     * A file has been added.
     *
     * @param AfterFileAddedEvent $event
     */
    public function afterFileAdded(AfterFileAddedEvent $event): void
    {
        $this->fileMod($event->getFolder());
    }

    /**
     * A file has been copied.
     *
     * @param AfterFileCopiedEvent $event
     */
    public function afterFileCopied(AfterFileCopiedEvent $event): void
    {
        $this->fileMod($event->getFolder());
    }

    /**
     * A file has been moved.
     *
     * @param AfterFileMovedEvent $event
     */
    public function afterFileMoved(AfterFileMovedEvent $event): void
    {
        $this->fileMod($event->getFolder());
        $this->fileMod($event->getOriginalFolder());
    }

    /**
     * A file has been renamed.
     *
     * @param AfterFileRenamedEvent $event
     */
    public function afterFileRenamed(AfterFileRenamedEvent $event): void
    {
        $this->fileMod($event->getFile()->getParentFolder());
    }

    /**
     * A file has been added as a *replacement* of an existing one.
     *
     * @param AfterFileReplacedEvent $event
     */
    public function afterFileReplaced(AfterFileReplacedEvent $event): void
    {
        $this->fileMod($event->getFile()->getParentFolder());
    }

    /**
     * A file has been created.
     *
     * @param AfterFileCreatedEvent $event
     */
    public function afterFileCreated(AfterFileCreatedEvent $event): void
    {
        $this->fileMod($event->getFolder());
    }

    /**
     * A file has been deleted.
     *
     * @param AfterFileDeletedEvent $event
     */
    public function afterFileDeleted(AfterFileDeletedEvent $event): void
    {
        try {
            $this->fileMod($event->getFile()->getParentFolder());
        } catch (\Exception $e) {
            // Exception may happen when a file is moved to /_recycler_/ but the user has no access to it
        }
    }

    /**
     * Contents of a file has been set.
     *
     * @param AfterFileContentsSetEvent $event
     */
    public function afterFileContentsSet(AfterFileContentsSetEvent $event): void
    {
        $this->fileMod($event->getFile()->getParentFolder());
    }

    /**
     * A folder has been added.
     *
     * @param AfterFolderAddedEvent $event
     */
    public function afterFolderAdded(AfterFolderAddedEvent $event): void
    {
        $this->fileMod($event->getFolder()->getParentFolder());
    }

    /**
     * A folder has been copied.
     *
     * @param AfterFolderCopiedEvent $event
     */
    public function afterFolderCopied(AfterFolderCopiedEvent $event): void
    {
        $this->fileMod($event->getFolder());
        $this->fileMod($event->getTargetFolder()->getParentFolder());
    }

    /**
     * A folder has been moved.
     *
     * @param AfterFolderMovedEvent $event
     */
    public function afterFolderMoved(AfterFolderMovedEvent $event): void
    {
        $this->fileMod($event->getFolder());
        $this->fileMod($event->getTargetFolder());
    }

    /**
     * A folder has been renamed.
     *
     * @param AfterFolderRenamedEvent $event
     */
    public function afterFolderRenamed(AfterFolderRenamedEvent $event): void
    {
        $this->fileMod($event->getFolder());
        $this->fileMod($event->getFolder()->getParentFolder());
    }

    /**
     * A folder has been deleted.
     *
     * @param AfterFolderDeletedEvent $event
     */
    public function afterFolderDeleted(AfterFolderDeletedEvent $event): void
    {
        $this->fileMod($event->getFolder());
        $this->fileMod($event->getFolder()->getParentFolder());
    }

}
