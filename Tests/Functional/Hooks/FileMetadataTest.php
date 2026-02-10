<?php

namespace Toumoro\TmCloudfront\Tests\Functional\Hooks;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\ActionService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class FileMetadataTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'toumoro/tm-cloudfront',
        'typo3/cms-scheduler'
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../DataSet/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../DataSet/sys_file_storage.csv');

        $this->setUpBackendUser(1);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront'] = [
            'distributionIds' => '{"www.example.com":"WWWWWWWWW","cdn.example.com":"CDNCDNCDNCDN"}',
            'mode' => 'table',
            'region' => 'us',
            'apikey' => 'AAAAAAAAAAAAAAA',
            'apisecret' => 'AAAAAAAAAAAAAAA',
            'version' => 'AAAAAAAAAAAAAAA',
        ];
    }

    /**
     * @test
     */
    public function editingFileMetadataDoesNotCreateRootInvalidation(): void
    {
        $storage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObject(1);

        // Create a test file outside of the storage path
        $tempFileName = tempnam(sys_get_temp_dir(), 'test-file');
        file_put_contents($tempFileName, 'test content');
        $file = $storage->addFile($tempFileName, $storage->getRootLevelFolder(), 'test-file.txt');

        // Clear any invalidations created during file upload
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_tmcloudfront_domain_model_invalidation')
            ->truncate('tx_tmcloudfront_domain_model_invalidation');

        // Simulate editing metadata via DataHandler
        $actionService = GeneralUtility::makeInstance(ActionService::class);
        $metadata = $file->getMetaData()->get();
        $actionService->modifyRecord('sys_file_metadata', (int)$metadata['uid'], [
            'title' => 'New Title'
        ]);

        // Assert that no "/" invalidation was created
        $rows = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');

        $pathSegments = array_column($rows, 'pathsegment');
        $this->assertNotContains('/', $pathSegments, 'Root invalidation "/" should not be created when editing file metadata.');
        $this->assertContains('/test-file.txt', $pathSegments, 'The file path should be invalidated when editing its metadata.');
    }
}
