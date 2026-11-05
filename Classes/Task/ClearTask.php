<?php

declare(strict_types=1);

/*
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Toumoro\TmCloudfront\Task;

use Toumoro\TmCloudfront\Service\InvalidationDispatcher;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Sends the invalidations queued in the database ("table" mode) to CloudFront.
 * Registered as a native (TCA based) scheduler task, see Configuration/TCA/Overrides/tx_scheduler_task.php.
 */
class ClearTask extends AbstractTask
{
    public function execute(): bool
    {
        return GeneralUtility::makeInstance(InvalidationDispatcher::class)->processQueue();
    }
}
