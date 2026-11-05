<?php

declare(strict_types=1);

/**
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 * @author Simon Ouellet <simon.ouellet@toumoro.com>
 *         Mehdi Guermazi <mehdi.guermazi@toumoro.com>
 *
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2025 Toumoro
 */

namespace Toumoro\TmCloudfront\Hooks;

use Toumoro\TmCloudfront\Cache\CloudFrontCacheManager;
use Toumoro\TmCloudfront\Configuration\CloudFrontConfiguration;
use Toumoro\TmCloudfront\Log\CloudFrontLogger;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * DataHandler "clearCachePostProc" hook: invalidates the speaking paths of pages when pages or content
 * elements are modified and when the "clear cache" buttons are used.
 *
 * Records of other tables are handled through cache tags, see \Toumoro\TmCloudfront\Cache\Backend\CloudFrontTagInvalidationBackend.
 */
class ClearCachePostProc
{
    protected const HANDLED_TABLES = ['tt_content', 'pages'];

    public function __construct(
        protected readonly CloudFrontCacheManager $cacheManager,
        protected readonly CloudFrontConfiguration $configuration,
        protected readonly SiteFinder $siteFinder,
        protected readonly CloudFrontLogger $logger,
    ) {}

    /**
     * @param array $params see DataHandler::prepareCacheFlush() and DataHandler::clear_cacheCmd()
     */
    public function clearCachePostProc(array $params, DataHandler $dataHandler): void
    {
        $table = (string)($params['table'] ?? '');
        // Only pages and content elements (e.g. not sys_file_metadata)
        if ($table !== '' && !in_array($table, self::HANDLED_TABLES, true)) {
            return;
        }
        // Do nothing when the editor is inside a workspace
        if ($dataHandler->BE_USER->workspace > 0) {
            return;
        }

        $cacheCmd = isset($params['cacheCmd']) ? (string)$params['cacheCmd'] : null;
        if ($cacheCmd === 'all' || $cacheCmd === 'pages') {
            // A clear cache button was clicked
            $this->cacheManager->cacheCmd($params);
        } elseif ($cacheCmd !== null && MathUtility::canBeInterpretedAsInteger($cacheCmd)) {
            // A page cache was cleared (page module button or TCEMAIN.clearCacheCmd)
            $this->clearPageCacheCommand((int)$cacheCmd, $params);
        } elseif ($table !== '') {
            if (($params['clearCacheEnabled'] ?? true) === false) {
                // TCEMAIN.clearCache_disable: the TYPO3 page cache is not cleared either
                return;
            }
            $this->clearRecord($table, (int)($params['uid'] ?? 0), (int)($params['uid_page'] ?? 0));
        }

        $this->cacheManager->clearCache();
    }

    protected function clearPageCacheCommand(int $pageId, array $params): void
    {
        if ($this->isSysFolder($pageId)) {
            return;
        }
        try {
            $domains = $this->cacheManager->getLanguagesDomains($pageId);
        } catch (SiteNotFoundException) {
            return;
        }
        $mappedDistributionIds = array_filter(
            array_map($this->configuration->getDistributionIdsForDomain(...), $domains),
            static fn(?string $ids): bool => $ids !== null
        );
        // No domain of the site is mapped (e.g. plain list of distribution ids): use all distributions
        $distributionIds = $mappedDistributionIds === []
            ? $this->configuration->getAllDistributionIds()
            : implode(',', $mappedDistributionIds);

        $this->logger->info('clearCachePostProc cacheCmd: ' . $pageId . ' distributionIds: ' . $distributionIds . ' domains: ' . implode(',', $domains));
        $this->cacheManager->cacheCmd($params, $distributionIds);
    }

    protected function clearRecord(string $table, int $uid, int $pageUid): void
    {
        // Pages: TSconfig and invalidation of the parent page. Content: TSconfig of its page.
        $parentId = $table === 'pages'
            ? (int)(BackendUtility::getRecord('pages', $pageUid, 'pid', '', false)['pid'] ?? 0)
            : $pageUid;
        $pagesTsConfig = BackendUtility::getPagesTSconfig($parentId);
        $distributionIds = (string)($pagesTsConfig['TCEMAIN.']['distributionIds'] ?? '')
            ?: $this->getDistributionIds($table, $uid, $pageUid);

        if ($table !== 'pages') {
            // Content element: clear its page only
            if ($this->isSysFolder($pageUid)) {
                return;
            }
            $this->cacheManager->queueClearCache($pageUid, false, $distributionIds);
        } else {
            // Page: clear the parent page and everything below it
            if (($pagesTsConfig['clearCache_disable'] ?? false) || $this->isSysFolder($pageUid)) {
                return;
            }
            $this->cacheManager->queueClearCache($parentId, true, $distributionIds);

            // Legacy: page TSconfig "clearCacheCmd" (outside TCEMAIN). TCEMAIN.clearCacheCmd is handled by the core,
            // which calls this hook again with "cacheCmd".
            $commands = GeneralUtility::trimExplode(',', strtolower((string)($pagesTsConfig['clearCacheCmd'] ?? '')), true);
            foreach (array_unique($commands) as $command) {
                $this->cacheManager->cacheCmd(['cacheCmd' => $command], $distributionIds);
            }
        }

        $this->logger->info('clearCachePostProc table: ' . $table . ' distributionIds: ' . $distributionIds);
    }

    /**
     * Distribution ids of the domain of the record language, all distributions when it can not be resolved.
     */
    protected function getDistributionIds(string $table, int $uid, int $pageUid): string
    {
        $domain = '';
        if ($pageUid > 0 && $uid > 0 && !$this->isPageDeleted($pageUid)) {
            // sys_language_uid = -1 ("all languages"): no single language to resolve. Every language
            // is invalidated anyway by CloudFrontCacheManager::queueClearCache().
            $languageId = (int)(BackendUtility::getRecord($table, $uid, 'sys_language_uid')['sys_language_uid'] ?? 0);
            if ($languageId >= 0) {
                try {
                    $language = $this->siteFinder->getSiteByPageId($pageUid)->getLanguageById($languageId);
                    $domain = $this->cacheManager->getLanguageHost($language);
                } catch (SiteNotFoundException|\InvalidArgumentException) {
                    // No site or language not available: fall back to all distributions
                }
            }
        }

        $distributionIds = $this->configuration->getDistributionIdsForDomainOrAll($domain);
        $this->logger->info('Get DistributionIds : ' . $distributionIds);
        return $distributionIds;
    }

    protected function isSysFolder(int $pageUid): bool
    {
        $page = BackendUtility::getRecord('pages', $pageUid, 'doktype');
        return (int)($page['doktype'] ?? 0) === PageRepository::DOKTYPE_SYSFOLDER;
    }

    protected function isPageDeleted(int $pageUid): bool
    {
        return (int)(BackendUtility::getRecord('pages', $pageUid, 'deleted', '', false)['deleted'] ?? 0) === 1;
    }
}
