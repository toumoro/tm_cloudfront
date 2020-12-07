<?php
defined('TYPO3_MODE') || die('Access denied.');

call_user_func(
    function()
    {

        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile('tm_cloudfront', 'Configuration/TypoScript', 'CloudFront cache');

        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addLLrefForTCAdescr('tx_tmcloudfront_domain_model_invalidation', 'EXT:tm_cloudfront/Resources/Private/Language/locallang_csh_tx_tmcloudfront_domain_model_invalidation.xlf');
        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('tx_tmcloudfront_domain_model_invalidation');

    }
);
