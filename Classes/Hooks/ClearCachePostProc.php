<?php

/**
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 * @author Simon Ouellet <simon.ouellet@toumoro.com>
 *         Mehdi Guermazi <mehdi.guermazi@toumoro.com>
 *
 *
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2025 Toumoro
 *
 ***/

namespace Toumoro\TmCloudfront\Hooks;

use Doctrine\DBAL\ParameterType;
use Toumoro\TmCloudfront\Cache\CloudFrontCacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

class ClearCachePostProc
{
    protected array $cloudFrontConfiguration = [];
    protected array $distributionsMapping = [];

    protected CloudFrontCacheManager $cacheManager;

    public function __construct(
        protected SiteFinder $siteFinder
    )
    {
        $this->cloudFrontConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('tm_cloudfront')['cloudfront'];
        $this->cacheManager = GeneralUtility::makeInstance(CloudFrontCacheManager::class);
        $this->distributionsMapping = $this->cacheManager->resolveDistributionIds();
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
    public function clearCachePostProc(&$params, &$pObj): void
    {
        // Reset the queue after processing for testing purposes
        $this->cacheManager->resetQueue();

        // Do nothing when editor is inside a workspace
        if ($pObj->BE_USER->workspace > 0) {
            return;
        }

        // Do nothing if the page is a sysfolder
        /* if ( (!empty($params['uid_page']) && MathUtility::canBeInterpretedAsInteger($params['uid_page'])) 
                || (isset($params['cacheCmd']) && MathUtility::canBeInterpretedAsInteger($params['cacheCmd'])) ) {
            $uid_page = (int)$params['uid_page'] ?: (int)$params['cacheCmd'];
            $pageRecord = BackendUtility::getRecord('pages', $uid_page, 'doktype');
            if (!empty($pageRecord) && (int)$pageRecord['doktype'] === PageRepository::DOKTYPE_SYSFOLDER) {
                return; // Do nothing if the page is a sysfolder
            }
        } */

        if (isset($params['cacheCmd']) && (in_array($params['cacheCmd'], ['all', 'pages']))) {
            // when a clear cache button is clicked
            $this->cacheManager->cacheCmd($params);
        } elseif (isset($params['cacheCmd']) && MathUtility::canBeInterpretedAsInteger($params['cacheCmd'])) {
            // when a clear cache button is clicked with a specific page ID or TsConfig command

            // Do nothing if the page is a sysfolder
            if ($this->isSysFolder((int)$params['cacheCmd'])) {
                // Do nothing if the page is a sysfolder
                return;
            }
            $uid_page = (int)$params['cacheCmd'];
            $domains = $this->cacheManager->getLanguagesDomains($uid_page);
            $distributionIds = $this->getDistributionsFromDomains($domains);

            $GLOBALS['BE_USER']->writelog(
                4,
                0,
                0,
                0,
                'clearCachePostProc cacheCmd: ' . $uid_page .
                    ' distributionIds: ' . $distributionIds .
                    ' domains: ' . implode(',', $domains),
                "tm_cloudfront"
            );

            $this->cacheManager->cacheCmd($params, $distributionIds);
        } else {
            $uid_page = (int)($params['uid_page'] ?? 0);
            $table = (string)($params['table'] ?? '');
            $parentId = $pObj->getPID($table, $uid_page);
            $tsConfig = BackendUtility::getPagesTSconfig($parentId);
            $distributionIds = $this->getDistributionIds($uid_page, $params);

            // Priority to TsConfig settings
            if (!empty($tsConfig['TCEMAIN.'])) {
                if(!empty($tsConfig['TCEMAIN.']['distributionIds'])) {
                    $distributionIds = $tsConfig['TCEMAIN.']['distributionIds'];
                }
            }

            // If the record is not a page, enqueue only the current page
            if ($table !== 'pages') {
                if ($this->isSysFolder($uid_page)) {
                    // Do nothing if the page is a sysfolder
                    return;
                }
                $this->cacheManager->queueClearCache($uid_page, false, $distributionIds);
            } else {
                // If the record is a page, enqueue the parent page
                if (
                    !$tsConfig['clearCache_disable']
                    && is_numeric($parentId)
                    && !$this->isSysFolder((int)$uid_page)
                ) {
                    $this->cacheManager->queueClearCache((int)$parentId, true, $distributionIds);
                } else {
                    // soit clearCache désactivé, soit pid invalide, soit page dossier système
                    return;
                }

                // Clear cache for pages entered in TSconfig:
                if (!empty($tsConfig['clearCacheCmd'])) {
                    $commands = GeneralUtility::trimExplode(',', strtolower($tsConfig['clearCacheCmd']), true);
                    foreach (array_unique($commands) as $cmdPart) {
                        $this->cacheManager->cacheCmd(['cacheCmd' => $cmdPart], $distributionIds);
                    }
                }
            }

            $GLOBALS['BE_USER']->writelog(
                4,
                0,
                0,
                0,
                'clearCachePostProc table: ' . $table . ' distributionIds: ' . $distributionIds,
                "tm_cloudfront"
            );
        }

        $this->cacheManager->clearCache();
    }

    protected function isSysFolder(int $uid_page): bool
    {
        $pageRecord = BackendUtility::getRecord('pages', $uid_page, 'doktype');
        return !empty($pageRecord) && (int)$pageRecord['doktype'] === PageRepository::DOKTYPE_SYSFOLDER;
    }

    protected function getDistributionsFromDomains(array $domains): string
    {
        $distributionIds = [];
        foreach ($domains as $domain) {
            if (isset($this->distributionsMapping[$domain])) {
                $distributionIds[] = $this->distributionsMapping[$domain];
            }
        }
        return implode(',', $distributionIds);
    }

    /**
     * Get distribution IDs based on the page ID and parameters.
     *
     * @param int $uid_page The UID of the page.
     * @param array $params Additional parameters that may contain table and uid.
     * @return string Comma-separated list of distribution IDs.
     */
    protected function getDistributionIds(int $uid_page, array $params): string
    {
        $domain = '';

        if ($uid_page > 0 && isset($params['table'], $params['uid']) && !$this->isPageDeleted($uid_page)) {
            $site = $this->siteFinder->getSiteByPageId($uid_page);

            $row = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable($params['table'])
                ->select('*')
                ->from($params['table'])
                ->where('uid = ' . (int)$params['uid'])
                ->executeQuery()
                ->fetchAssociative();

            $sysLanguageUid = $row['sys_language_uid'] ?? 0;
            $language = $site->getLanguageById($sysLanguageUid);
            $domain = $language->getBase()->getHost();
        }

        $distributionIds = $this->distributionsMapping[$domain] ?? implode(',', array_values($this->distributionsMapping));

        $GLOBALS['BE_USER']->writelog(
            4,
            0,
            0,
            0,
            'Get DistributionIds : ' . $distributionIds,
            "tm_cloudfront"
        );

        return $distributionIds;
    }

    private function isPageDeleted($uid): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');

        $queryBuilder->getRestrictions()->removeAll();

       return (int)$queryBuilder
            ->select('deleted')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)
                )
            )
            ->executeQuery()
            ->fetchOne();
    }
}
