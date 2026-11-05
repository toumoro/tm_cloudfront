<?php

declare(strict_types=1);

/*
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Toumoro\TmCloudfront\Cache\Backend;

use Toumoro\TmCloudfront\Cache\CloudFrontCacheManager;
use Toumoro\TmCloudfront\Configuration\CloudFrontConfiguration;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stores nothing. Being in the "pages" cache group, it is notified each time TYPO3 flushes page cache tags
 * (record saved with the DataHandler, TCEMAIN.clearCacheCmd = cacheTag:xyz, extensions calling
 * CacheManager::flushCachesInGroupByTags('pages', ...)) and forwards the configured tags ("cacheTags" setting)
 * to CloudFront as tag invalidations ("#tag").
 *
 * The tags are sent to CloudFront by the origin in a response header, see CacheTagHeaderMiddleware.
 */
class CloudFrontTagInvalidationBackend extends NullBackend
{
    public const CACHE_IDENTIFIER = 'tm_cloudfront_tags';

    public function flushByTag(string $tag): void
    {
        $this->flushByTags([$tag]);
    }

    public function flushByTags(array $tags): void
    {
        $configuration = GeneralUtility::makeInstance(CloudFrontConfiguration::class);
        if ($configuration->getCacheTagPrefixes() === []) {
            return;
        }
        $tags = array_values(array_unique(array_filter(
            array_map('strval', $tags),
            $configuration->isCloudFrontCacheTag(...)
        )));
        if ($tags !== []) {
            GeneralUtility::makeInstance(CloudFrontCacheManager::class)->invalidateTags($tags);
        }
    }
}
