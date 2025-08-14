<?php

namespace Toumoro\TmCloudfront\Hooks;

use Toumoro\TmCloudfront\Cache\CloudFrontCacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

class ClearCachePostProc
{
    protected array $cloudFrontConfiguration = [];
    protected array $distributionsMapping = [];

    protected CloudFrontCacheManager $cacheManager;

    public function __construct()
    {
        $this->cloudFrontConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('tm_cloudfront')['cloudfront'];
        $this->distributionsMapping = $this->resolveDistributionIds();
        $this->cacheManager = GeneralUtility::makeInstance(CloudFrontCacheManager::class);
    }

    public function clearCachePostProc(&$params, &$pObj): void
    {
        if ($pObj->BE_USER->workspace > 0) {
            return;
        }

        if (isset($params['cacheCmd']) && $params['cacheCmd'] == 'all') {
            $this->cacheCmd($params);
        } elseif (isset($params['cacheCmd']) && MathUtility::canBeInterpretedAsInteger($params['cacheCmd'])) {
            $uid_page = (int)$params['cacheCmd'];
            $domains = $this->cacheManager->getLanguagesDomains($uid_page);
            $distributionIds = $this->getDistributionsFromDomains($domains);

            $GLOBALS['BE_USER']->writelog(
                4, 0, 0, 0,
                'clearCachePostProc cacheCmd: ' . $uid_page .
                ' distributionIds: ' . $distributionIds .
                ' domains: ' . implode(',', $domains),
                "tm_cloudfront"
            );

            $this->cacheCmd($params, $distributionIds);
        } else {
            $uid_page = (int)($params['uid_page'] ?? 0);
            $table = (string)($params['table'] ?? '');
            $parentId = $pObj->getPID($table, $uid_page);
            $tsConfig = BackendUtility::getPagesTSconfig($parentId);
            $distributionIds = $this->getDistributionIds($uid_page, $params);

            if (!empty($tsConfig['distributionIds'])) {
                $distributionIds = $tsConfig['distributionIds'];
            }

            if ($table !== 'pages') {
                $this->cacheManager->queueClearCache($uid_page, false, $distributionIds);
            } else {
                if (!$tsConfig['clearCache_disable']) {
                    if (is_numeric($parentId)) {
                        $this->cacheManager->queueClearCache((int)$parentId, true, $distributionIds);
                    } else {
                        return;
                    }
                }
                // Clear cache for pages entered in TSconfig:
                if (!empty($tsConfig['clearCacheCmd'])) {
                    $commands = GeneralUtility::trimExplode(',', strtolower($tsConfig['clearCacheCmd']), true);
                    foreach (array_unique($commands) as $cmdPart) {
                        $this->cacheCmd(['cacheCmd' => $cmdPart], $distributionIds);
                    }
                }
            }

            $GLOBALS['BE_USER']->writelog(
                4, 0, 0, 0,
                'clearCachePostProc table: ' . $table . ' distributionIds: ' . $distributionIds,
                "tm_cloudfront"
            );
        }

        $this->cacheManager->clearCache();
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

    protected function getDistributionIds(int $uid_page, array $params): string
    {
        $domain = '';
        if ($uid_page > 0 && isset($params['table'], $params['uid'])) {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($uid_page);
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
            4, 0, 0, 0,
            'Get DistributionIds : ' . $distributionIds,
            "tm_cloudfront"
        );

        return $distributionIds;
    }

    protected function resolveDistributionIds(): array
    {
        $mapping = $this->cloudFrontConfiguration['distributionIds'] ?? '';
        if ($mapping && is_string($mapping) && $mapping[0] === '{') {
            $mappingArray = json_decode($mapping, true);
            if (is_array($mappingArray)) {
                return $mappingArray;
            }
        }
        return explode(',', $mapping);
    }

    /**
     * This function handles the cache clearing buttons and clearCacheCmd tsconfig
     * @param array $params
     * @param array $distributionIds comma seperated list of distributions ids, NULL means all (defined in the extension configuration)
     * @return void
     */
    protected function cacheCmd(array $params, string|null $distributionIds): void
    {
        if (($params['cacheCmd'] ?? '') === "all" || ($params['cacheCmd'] ?? '') === "pages") {
            $this->cacheManager->queueClearCache(0, true);
        } elseif (MathUtility::canBeInterpretedAsInteger($params['cacheCmd'] ?? '')) {
            $this->cacheManager->queueClearCache((int)$params['cacheCmd'], false, $distributionIds);
        }
    }
}
