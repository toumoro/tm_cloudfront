<?php

declare(strict_types=1);

use Toumoro\TmCloudfront\Hooks\ClearCachePostProc;
use Toumoro\TmCloudfront\Task\ClearTask;

defined('TYPO3') or die();

$_EXTKEY = 'tm_cloudfront';

// Clear cache
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc']['tm_cloudfront'] =
    ClearCachePostProc::class . '->clearCachePostProc';

// Add caching framework garbage collection task
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][ClearTask::class] = array(
        'extension' => 'tm_cloudfront',
        'title' => 'LLL:EXT:tm_cloudfront/Resources/Private/Language/locallang.xlf:tx_tmcloudfront_task_name',
        'description' => '',
);
