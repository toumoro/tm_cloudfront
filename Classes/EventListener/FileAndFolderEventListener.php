<?php

/**
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 * @author Simon Ouellet <simon.ouellet@toumoro.com>
 *         Mehdi Guermazi <mehdi.guermazi@toumoro.com>
 *
 *
 * This file is part of the "CloudFront cache" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2025 Toumoro
 *
 ***/

namespace Toumoro\TmCloudfront\EventListener;

use Toumoro\TmCloudfront\Cache\CloudFrontCacheManager;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\Event\{
    AfterFileMovedEvent,
    AfterFileRenamedEvent,
    AfterFileReplacedEvent,
    AfterFileDeletedEvent,
    AfterFileContentsSetEvent,
    AfterFolderMovedEvent,
    AfterFolderRenamedEvent,
    AfterFileMetaDataUpdatedEvent,
    AfterFolderDeletedEvent
};

class FileAndFolderEventListener
{
    protected CloudFrontCacheManager $cacheManager;

    public function __construct(CloudFrontCacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    public function afterFileMoved(AfterFileMovedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getOriginalFolder());
    }

    public function afterFileRenamed(AfterFileRenamedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFile()->getParentFolder());
    }

    public function afterFileReplaced(AfterFileReplacedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFile());
    }

    public function afterFileDeleted(AfterFileDeletedEvent $event): void
    {
        try {
            $this->cacheManager->fileMod($event->getFile());
        } catch (\Exception $e) {
        }
    }

    public function afterFileContentsSet(AfterFileContentsSetEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFile());
    }

    public function afterFolderMoved(AfterFolderMovedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFolder());
        $this->cacheManager->fileMod($event->getTargetFolder());
    }

    public function afterFolderRenamed(AfterFolderRenamedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFolder()->getParentFolder());
    }

    public function afterFolderDeleted(AfterFolderDeletedEvent $event): void
    {
        $this->cacheManager->fileMod($event->getFolder());
    }
    public function afterMetadataUpdated(AfterFileMetaDataUpdatedEvent $event): void
    {

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $file = $resourceFactory->getFileObject($event->getFileUid());

        $this->cacheManager->fileMod($file);
    }
}
