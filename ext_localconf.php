<?php

defined('TYPO3') or die('Access denied.');

$_EXTKEY = 'tm_cloudfront';

// Clear cache
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc']['tm_cloudfront'] = 'Toumoro\TmCloudfront\Hooks\ClearCachePostProc->clearCachePostProc';

$signalSlotDispatcher = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\SignalSlot\Dispatcher::class);

$signalsToRegister = [
    'File Index Record created' => [
        \TYPO3\CMS\Core\Resource\ResourceStorage::class, 'postFileAdd',\Toumoro\TmCloudfront\Hooks\ClearCachePostProc::class, 'fileMod'  ],
    'File Index Record replace' => [
        \TYPO3\CMS\Core\Resource\ResourceStorage::class, 'postFileReplace',\Toumoro\TmCloudfront\Hooks\ClearCachePostProc::class, 'fileMod'  ],
    'File Index Record updated' => [
        \TYPO3\CMS\Core\Resource\ResourceStorage::class, 'recordUpdated',\Toumoro\TmCloudfront\Hooks\ClearCachePostProc::class, 'fileMod' ],
];
foreach ($signalsToRegister as $parameters) {
    $signalSlotDispatcher->connect($parameters[0], $parameters[1], $parameters[2], $parameters[3]);
}


// Add caching framework garbage collection task
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Toumoro\TmCloudfront\Task\ClearTask::class] = array(
        'extension' => 'tm_cloudfront',
        'title' => 'LLL:EXT:tm_cloudfront/Resources/Private/Language/locallang.xlf:tx_tmcloudfront_task_name',
        'description' => '',
);
