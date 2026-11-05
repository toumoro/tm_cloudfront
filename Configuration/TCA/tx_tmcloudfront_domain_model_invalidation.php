<?php

// Queue of pending invalidations ("table" mode), processed by the scheduler task
return [
    'ctrl' => [
        'title' => 'LLL:EXT:tm_cloudfront/Resources/Private/Language/locallang_db.xlf:tx_tmcloudfront_domain_model_invalidation',
        'label' => 'pathsegment',
        'label_alt' => 'distributionId',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'default_sortby' => 'uid',
        'iconfile' => 'EXT:tm_cloudfront/Resources/Public/Icons/tx_tmcloudfront_domain_model_invalidation.gif',
        'rootLevel' => -1,
        'adminOnly' => true,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => ['showitem' => 'pathsegment, distributionId'],
    ],
    'columns' => [
        'pathsegment' => [
            'label' => 'LLL:EXT:tm_cloudfront/Resources/Private/Language/locallang_db.xlf:tx_tmcloudfront_domain_model_invalidation.pathsegment',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 3,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'distributionId' => [
            'label' => 'LLL:EXT:tm_cloudfront/Resources/Private/Language/locallang_db.xlf:tx_tmcloudfront_domain_model_invalidation.distributionId',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
    ],
];
