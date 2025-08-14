<?php

namespace Toumoro\TmCloudfront\Cache;

use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\File;
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
     * @param Folder|File $resource The resource that has been modified.
     * @return void
     */
    public function fileMod(Folder|File $resource): void
    {
        $storage = $resource->getStorage();
        $storageConfig = $storage->getConfiguration();

        // si $storageConfig['publicBaseUrl'] est vide c'est un Local driver et on invvalide toutes les distributions
        $domain = $storageConfig['publicBaseUrl'] ?? '';

        $distributionIds = $this->distributionsMapping[$domain] ?? implode(',', array_values($this->distributionsMapping));
        $wildcard = $resource instanceof Folder ? '/*' : '';

        $this->enqueue($resource->getIdentifier() . $wildcard, $distributionIds);
        $this->clearCache();

        $errorMessage = 'fileMod distributionsIds : ' . $distributionIds . ' resource identifier : ' . $resource->getIdentifier() . ' wildcard : ' . $wildcard;
        $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $errorMessage, "tm_cloudfront");

        // Reset the queue after processing for testing purposes
        $this->queue = [];
    }

    /**
     * Enqueue a link to be cleared in CloudFront cache.
     *
     * @param string $link The link to enqueue.
     * @param string $distributionIds Comma-separated list of distribution IDs.
     */
    public function enqueue(string $link, string $distributionIds): void
    {
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
                    $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $errorMessage, "tm_cloudfront");
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
     * Retourne le(s) distributionId(s) à utiliser selon le domaine et la config
     *
     * @return string
     */
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
        $errorMessage = 'queueClearCache $pageId: ' . $pageId . ' recursive: ' . $recursive . ' distributionIds: '.$distributionIds;
        $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $errorMessage, "tm_cloudfront");

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
                if($this->isMultiLanguageDomains($entry)){
                    $this->enqueue($this->buildLink($entry, array('_language' => 0)) . $wildcard, $this->distributionsMapping[$languages[0]->getBase()->getHost()]);
                    foreach ($languages as $k => $lang) {
                        if ($lang->getLanguageId() != 0) {
                            $this->enqueue($this->buildLink($entry, array('_language' => $lang->getLanguageId())) . $wildcard, $this->distributionsMapping[$lang->getBase()->getHost()]);
                        }
                    }
                } else{
                    $this->enqueue($this->buildLink($entry, array('_language' => 0)) . $wildcard, $distributionIds);
                    $errorMessage = 'queueClearCache enque lang: 0  distributionIds: '.$distributionIds;
                    $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $errorMessage, "tm_cloudfront");
                    foreach ($languages as $k => $lang) {
                        if ($lang->getLanguageId() != 0) {
                            $this->enqueue($this->buildLink($entry, array('_language' => $lang->getLanguageId())) . $wildcard, $distributionIds);
                            $errorMessage = 'queueClearCache enque lang: ' . $lang->getLanguageId() . ' distributionIds: '.$distributionIds;
                            $GLOBALS['BE_USER']->writelog(4, 0, 0, 0, $errorMessage, "tm_cloudfront");
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

    protected function getLanguagesDomains(int $uid_page): array
    {
        $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($uid_page);
        $domains = [];
        foreach ($site->getAllLanguages() as $language) {
            $domains[$language->getLanguageId()] = $language->getBase()->getHost();
        }
        return $domains;
    }

    protected function isMultiLanguageDomains(int $uid_page): bool
    {
        $multi = true;
        $domains = $this->getLanguagesDomains($uid_page);
        foreach ($domains as $lang => $domain){
            if (strpos($domain, '.') === false) {
                $multi = false;
            }
        }
        return $multi;
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
}
