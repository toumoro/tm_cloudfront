<?php

declare(strict_types=1);

use Toumoro\TmCloudfront\Task\ClearTask;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Native (TCA based) scheduler task: sends the invalidations queued in "table" mode
ExtensionManagementUtility::addRecordType(
    [
        'label' => 'LLL:EXT:tm_cloudfront/Resources/Private/Language/locallang.xlf:tx_tmcloudfront_task_name',
        'description' => 'LLL:EXT:tm_cloudfront/Resources/Private/Language/locallang.xlf:tx_tmcloudfront_task_description',
        'value' => ClearTask::class,
        'icon' => 'mimetypes-x-tx_scheduler_task_group',
        'group' => 'tm_cloudfront',
    ],
    '
        --div--;core.form.tabs:general,
            tasktype,
            task_group,
            description,
        --div--;core.form.tabs:timing,
            --palette--;;execution,
        --div--;core.form.tabs:access,
            disable,
        --div--;core.form.tabs:extended,',
    [],
    '',
    'tx_scheduler_task'
);
