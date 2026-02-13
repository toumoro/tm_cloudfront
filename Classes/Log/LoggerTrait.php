<?php

namespace Toumoro\TmCloudfront\Log;

use TYPO3\CMS\Core\Log\Logger;

trait LoggerTrait
{
    /**
     * Surcharge conforme à BackendUserAuthentication::writelog
     *
     */
    protected function writelog(...$args) {
        if ($this->cloudFrontConfiguration["disableLog"] ?? false) return;
        if(!isset($GLOBALS['BE_USER'])) return;
        $GLOBALS['BE_USER']->writelog(...$args);
    }
}