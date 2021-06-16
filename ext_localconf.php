<?php

// Clear cache
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc'][$_EXTKEY] = 'Toumoro\TmCloudfront\Hooks\ClearCachePostProc->clearCachePostProc';

$signalSlotDispatcher = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\SignalSlot\Dispatcher::class);

$signalsToRegister = [
    'File Index Record created' => [
        \TYPO3\CMS\Core\Resource\ResourceStorage::class, 'postFileAdd',\Toumoro\TmCloudfront\Hooks\ClearCachePostProc::class, 'fileMod'  ],
    'File Index Record updated' => [
        \TYPO3\CMS\Core\Resource\ResourceStorage::class, 'recordUpdated',\Toumoro\TmCloudfront\Hooks\ClearCachePostProc::class, 'fileMod' ],
];
foreach ($signalsToRegister as $parameters) {
    $signalSlotDispatcher->connect($parameters[0], $parameters[1], $parameters[2], $parameters[3]);
}


// Add caching framework garbage collection task
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\Toumoro\TmCloudfront\Task\ClearTask::class] = array(
        'extension' => $_EXTKEY,
        'title' => 'LLL:EXT:' . $_EXTKEY . '/Resources/Private/Language/locallang.xlf:tx_tmcloudfront_task_name',
        'description' => '',
);
