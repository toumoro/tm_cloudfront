<?php

declare(strict_types=1);

use Toumoro\TmCloudfront\Cache\Backend\CloudFrontTagInvalidationBackend;
use Toumoro\TmCloudfront\Hooks\ClearCachePostProc;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

defined('TYPO3') or die();

// Invalidate pages and content elements, clear cache buttons
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc']['tm_cloudfront'] =
    ClearCachePostProc::class . '->clearCachePostProc';

// CloudFront tag invalidation: stores nothing, receives the page cache tag flushes ("pages" group)
// and invalidates the tags configured in the "cacheTags" extension setting.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][CloudFrontTagInvalidationBackend::CACHE_IDENTIFIER] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][CloudFrontTagInvalidationBackend::CACHE_IDENTIFIER] += [
    'frontend' => VariableFrontend::class,
    'backend' => CloudFrontTagInvalidationBackend::class,
    'groups' => ['pages'],
];
