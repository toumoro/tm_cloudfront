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

namespace Toumoro\TmCloudfront\Cache;

use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Class CloudFrontCacheManager
 * This class manages the cache for CloudFront, handling invalidations and enqueuing resources.
 */
class CloudFrontCacheManager
{
    protected array $queue = [];
    protected array $distributionsMapping = [];
    protected array $cloudFrontConfiguration = [];

    public function __construct()
    {
        $this->cloudFrontConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('tm_cloudfront')['cloudfront'];
        $this->distributionsMapping = $this->resolveDistributionIds();
    }

    /**
     * This function handles the cache clearing for files and folders.
     * It enqueues the resource identifier and distribution IDs for invalidation.
     *
     * @param Folder|File|ProcessedFile $resource The resource that has been modified.
     * @return void
     */
    public function fileMod(Folder|File|ProcessedFile $resource): void
    {
        // Skip processed files that are already processed
        if($resource instanceof ProcessedFile) {
            if($resource->isProcessed()) return;
        }

        $storage = $resource->getStorage();
        $storageConfig = $storage->getConfiguration();

        // si $storageConfig['publicBaseUrl'] est vide c'est un Local driver et on invvalide toutes les distributions
        $domain = $storageConfig['publicBaseUrl'] ?? '';

        $distributionIds = $this->distributionsMapping[$domain] ?? implode(',', array_values($this->distributionsMapping));
        $wildcard = $resource instanceof Folder ? '/*' : '';

        $this->enqueue($resource->getIdentifier() . $wildcard, $distributionIds);
        $this->clearCache();

        if(isset($GLOBALS['BE_USER'])) {
            $errorMessage = 'fileMod distributionsIds : ' . $distributionIds . ' resource identifier : ' . $resource->getIdentifier() . ' wildcard : ' . $wildcard;
            $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $errorMessage, ["ext" => "tm_cloudfront"]);
        }

        // Reset the queue after processing for testing purposes
        $this->resetQueue();
    }

    /**
     * This function handles the cache clearing buttons and clearCacheCmd tsconfig
     * @param array $params
     * @param string|null $distributionIds comma seperated list of distributions ids, NULL means all (defined in the extension configuration)
     * @return void
     */
    public function cacheCmd(array $params, string|null $distributionIds = null): void
    {
        if (($params['cacheCmd'] ?? '') === "all" || ($params['cacheCmd'] ?? '') === "pages") {
            $this->queueClearCache(0, true);
        } elseif (MathUtility::canBeInterpretedAsInteger($params['cacheCmd'] ?? '')) {
            $this->queueClearCache((int)$params['cacheCmd'], false, $distributionIds);
        }
    }

    /**
     * Enqueue a link to be cleared in CloudFront cache.
     *
     * @param string $link The link to enqueue.
     * @param string $distributionIds Comma-separated list of distribution IDs.
     */
    public function enqueue(string $link, string $distributionIds): void
    {
        if (!$distributionIds) {
            return;
        }
        if ($link === '*') {
            $link = '/*';
        }
        $link = str_replace('//', '/', $link);
        if (substr($link, 0, 1) !== '/') {
            $link = '/' . $link;
        }

        foreach (explode(',', $distributionIds) as $value) {
            $value = trim($value);
            if ($value !== '') {
                $this->queue[$value][] = $link;
            }
        }
    }

    /**
     * This function sends a Cloudfront invalidate query based on the cache queue ($this->queue).
     *
     * @return void
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function clearCache()
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
                $queryBuilder
                    ->delete('tx_tmcloudfront_domain_model_invalidation')
                    ->where(
                        $queryBuilder->expr()->eq('distributionId', $queryBuilder->createNamedParameter($distId))
                    )
                    ->executeStatement();
            }

            if (((!empty($this->cloudFrontConfiguration['mode'])) && ($this->cloudFrontConfiguration['mode'] == 'live')) || ($force)) {
                $cloudFront = GeneralUtility::makeInstance('Aws\CloudFront\CloudFrontClient', $options);

                try {
                    $cloudFront->createInvalidation([
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
                    // log error: could not create invalidation
                    $errorMessage = 'Could not create invalidation for distribution ID ' . $distId . ': ' . $e->getMessage();
                    $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $errorMessage, ["ext" => "tm_cloudfront"]);
                }
            } else {
                foreach ($paths as $k => $value) {
                    // if id exists, do not insert it again
                    $id = md5($value . $distId);
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                        ->getQueryBuilderForTable('tx_tmcloudfront_domain_model_invalidation');
                    $row = $queryBuilder->select('uid')
                        ->from('tx_tmcloudfront_domain_model_invalidation')
                        ->where(
                            $queryBuilder->expr()->eq('id', $queryBuilder->createNamedParameter($id, Connection::PARAM_STR))
                        )
                        ->executeQuery()
                        ->fetchAssociative();
                    if (!$row) {
                        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                            ->getConnectionForTable('tx_tmcloudfront_domain_model_invalidation');
                        $connection->insert(
                            'tx_tmcloudfront_domain_model_invalidation',
                            [
                                'pathsegment' => $value,
                                'distributionId' => $distId,
                                'id' => md5($value . $distId),
                            ]
                        );
                    }
                }
            }
        }
    }

    /**
     * Returns the distributionId(s) to use based on the domain and config
     *
     * @return array
     */
    public function resolveDistributionIds(): array
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
    public function queueClearCache(int $pageId, bool $recursive = false, string|null $distributionIds = null)
    {
        $errorMessage = 'queueClearCache $pageId: ' . $pageId . ' recursive: ' . $recursive . ' distributionIds: ' . $distributionIds;
        $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $errorMessage, ["ext" => "tm_cloudfront"]);

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
            $distributionIds = implode(',', array_values($this->distributionsMapping));
        }

        if (MathUtility::canBeInterpretedAsInteger($entry)) {
            $languages = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageId)->getAllLanguages();

            if (count($languages) > 0) {
                if ($this->isMultiLanguageDomains($entry)) {
                    $this->enqueue($this->buildLink($entry, array('_language' => 0)) . $wildcard, $this->distributionsMapping[$this->getLanguageHost($languages[0])]);
                    foreach ($languages as $k => $language) {
                        if ($language->getLanguageId() != 0) {
                            $this->enqueue($this->buildLink($entry, array('_language' => $language->getLanguageId())) . $wildcard, $this->distributionsMapping[$this->getLanguageHost($language)]);
                        }
                    }
                } else {
                    $this->enqueue($this->buildLink($entry, array('_language' => 0)) . $wildcard, $distributionIds);
                    $errorMessage = 'queueClearCache enque lang: 0  distributionIds: ' . $distributionIds;
                    $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $errorMessage, ["ext" => "tm_cloudfront"]);
                    foreach ($languages as $k => $lang) {
                        if ($lang->getLanguageId() != 0) {
                            $this->enqueue($this->buildLink($entry, array('_language' => $lang->getLanguageId())) . $wildcard, $distributionIds);
                            $errorMessage = 'queueClearCache enque lang: ' . $lang->getLanguageId() . ' distributionIds: ' . $distributionIds;
                            $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $errorMessage, ["ext" => "tm_cloudfront"]);
                        }
                    }
                }
            } else {
                $this->enqueue($this->buildLink($entry) . $wildcard, $distributionIds);
            }
        } else {
            $this->enqueue($entry . $wildcard, $distributionIds);
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
    protected function buildLink(int $pageUid, array $linkArguments = []): string
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
     * Get the domains for all languages of a page.
     *
     * @param int $uid_page The UID of the page.
     * @return array An associative array of language IDs and their corresponding domains.
     */
    public function getLanguagesDomains(int $uid_page): array
    {
        $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($uid_page);
        $domains = [];
        // Récupération de l'hôte actuel via la requête si disponible
        foreach ($site->getAllLanguages() as $language) {
            $domains[$language->getLanguageId()] = $this->getLanguageHost($language);
        }
        return $domains;
    }

    public function getLanguageHost(SiteLanguage $language): string {
        if ($host = $language->getBase()->getHost()) {
            return $host;
        }
        $currentRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($currentRequest
            && $currentRequest instanceof \Psr\Http\Message\ServerRequestInterface) {
            return $currentRequest->getUri()->getHost();
        }
        return '';
    }

    /**
     * Check if the page has multiple languages with different domains.
     *
     * @param int $uid_page The UID of the page.
     * @return bool True if there are multiple languages with different domains, false otherwise.
     */
    public function isMultiLanguageDomains(int $uid_page): bool
    {;
        $domains = $this->getLanguagesDomains($uid_page);

        return count(array_unique(array_map('strtolower', $domains))) > 1;
    }

    /**
     * Generate a random string
     * @param int $length length of the string
     * @return string
     */
    protected function generateRandomString(int $length = 10): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    public function resetQueue()
    {
        $this->queue = [];
    }
}
