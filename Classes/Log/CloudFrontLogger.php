<?php

declare(strict_types=1);

/*
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Toumoro\TmCloudfront\Log;

use Toumoro\TmCloudfront\Configuration\CloudFrontConfiguration;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;

/**
 * Writes to the TYPO3 system log (backend "Log" module), as the extension always did.
 * Can be disabled with the "disableLog" extension setting.
 */
class CloudFrontLogger
{
    public function __construct(
        private readonly CloudFrontConfiguration $configuration,
    ) {}

    public function info(string $message): void
    {
        $this->write($message, SystemLogErrorClassification::MESSAGE);
    }

    public function error(string $message): void
    {
        $this->write($message, SystemLogErrorClassification::SYSTEM_ERROR);
    }

    private function write(string $message, int $error): void
    {
        if ($this->configuration->isLogDisabled()) {
            return;
        }
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return;
        }
        $backendUser->writelog(SystemLogType::EXTENSION, 0, $error, 0, $message, ['ext' => CloudFrontConfiguration::EXTENSION_KEY]);
    }
}
