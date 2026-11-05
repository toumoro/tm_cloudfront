<?php

declare(strict_types=1);

/**
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 * @author Simon Ouellet <simon.ouellet@toumoro.com>
 *         Mehdi Guermazi <mehdi.guermazi@toumoro.com>
 *
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2025 Toumoro
 */

namespace Toumoro\TmCloudfront\EventListener;

use Toumoro\TmCloudfront\Cache\CloudFrontCacheManager;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Resource\Event\AfterFileContentsSetEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileMovedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileRenamedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFolderDeletedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFolderMovedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFolderRenamedEvent;

/**
 * Invalidates files and folders in CloudFront when they are modified in the FAL.
 */
final readonly class FileAndFolderEventListener
{
    public function __construct(
        private CloudFrontCacheManager $cacheManager,
    ) {}

    #[AsEventListener('tm.afterFileMoved')]
    public function afterFileMoved(AfterFileMovedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getOriginalFolder());
    }

    #[AsEventListener('tm.afterFileRenamed')]
    public function afterFileRenamed(AfterFileRenamedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFile()->getParentFolder());
    }

    #[AsEventListener('tm.afterFileReplaced')]
    public function afterFileReplaced(AfterFileReplacedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFile());
    }

    #[AsEventListener('tm.afterFileDeleted')]
    public function afterFileDeleted(AfterFileDeletedEvent $event): void
    {
        try {
            $this->cacheManager->fileMod($event->getFile());
        } catch (\Exception) {
            // The file (or its storage) may not be resolvable anymore
        }
    }

    #[AsEventListener('tm.afterFileContentsSet')]
    public function afterFileContentsSet(AfterFileContentsSetEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFile());
    }

    #[AsEventListener('tm.afterFolderMoved')]
    public function afterFolderMoved(AfterFolderMovedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFolder());
        if ($event->getTargetFolder() !== null) {
            $this->cacheManager->fileMod($event->getTargetFolder());
        }
    }

    #[AsEventListener('tm.afterFolderRenamed')]
    public function afterFolderRenamed(AfterFolderRenamedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFolder()->getParentFolder());
    }

    #[AsEventListener('tm.afterFolderDeleted')]
    public function afterFolderDeleted(AfterFolderDeletedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFolder());
    }
}
