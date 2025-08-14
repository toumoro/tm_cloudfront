<?php

namespace Toumoro\TmCloudfront\EventListener;

use Toumoro\TmCloudfront\Cache\CloudFrontCacheManager;
use TYPO3\CMS\Core\Resource\Event\{AfterFileMovedEvent, AfterFileRenamedEvent, AfterFileReplacedEvent,
    AfterFileDeletedEvent, AfterFileContentsSetEvent, AfterFolderMovedEvent, AfterFolderRenamedEvent, AfterFolderDeletedEvent};

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
        } catch (\Exception $e) {}
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
}
