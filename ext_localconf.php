<?php

// Clear cache
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc'][$_EXTKEY] = 'Toumoro\TmCloudfront\Hooks\ClearCachePostProc->clearCachePostProc';

// Add caching framework garbage collection task
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Toumoro\TmCloudfront\Task\ClearTask::class] = array(
        'extension' => $_EXTKEY,
        'title' => 'LLL:EXT:' . $_EXTKEY . '/Resources/Private/Language/locallang.xlf:tx_tmcloudfront_task_name',
        'description' => '',
);
