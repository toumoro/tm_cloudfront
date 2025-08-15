<?php

/**
 * Thanks to Tim Lochmüller for sharing his code (nc_staticfilecache)
 * @author Simon Ouellet
 */

namespace Toumoro\TmCloudfront\Tests\Unit\Hooks;


use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\ActionService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ClearCachePostProcTest extends FunctionalTestCase
{
    /**
     * @var array Have styleguide loaded
     */
    protected array $testExtensionsToLoad = [
        'toumoro/tm-cloudfront',
        'typo3/cms-scheduler'
    ];

    /**
     * @var array file cache invalidation tests
     */
    protected array $fileFolderTests;

    /**
     * @var array content and page cache invalidation tests
     */
    protected array $contentPageTests;

    /**
     * @var array languages
     */
    protected array $languages;

    protected ActionService $actionService;

    /**
     * pages table structure
     * +-----+-----+-------------------+------------------+-------------+----------+
     * | uid | pid | title             | sys_language_uid | l10n_parent | slug     |
     * +-----+-----+-------------------+------------------+-------------+----------+
     * |   1 |   0 | Startpage         |                0 |           0 | /        |
     * |   2 |   0 | Startpage - Dansk |                1 |           1 | /        |
     * |   3 |   1 | Subpage           |                0 |           0 | /sub     |
     * |   4 |   1 | Subpage - Dansk   |                1 |           3 | /subtest |
     * |   5 |   3 | Testing 1         |                0 |           0 | /sub/sub |
     * |   6 |   3 | Sub Subpage       |                1 |           5 | /sub/sub |
     * +-----+-----+-------------------+------------------+-------------+----------+
     * 
     * tt_content table structure
     * +-----+-----+-------------------+------------------+-------------+----------+
     * | uid | pid | header            | sys_language_uid | l18n_parent | colPos   |
     * +-----+-----+-------------------+------------------+-------------+----------+
     * |   1 |   5 | Content 1         |                0 |           0 |        0 |
     * |   2 |   5 | Content 2         |                1 |           1 |        0 |
     * +-----+-----+-------------------+------------------+-------------+----------+
     **/
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../DataSet/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../DataSet/translated_subpages.csv');
        $this->importCSVDataSet(__DIR__ . '/../DataSet/sys_file_storage.csv');
        $this->importCSVDataSet(__DIR__ . '/../DataSet/tt_content.csv');

        $this->fileFolderTests = Yaml::parseFile( __DIR__ . '/../Fixtures/fileFolderTests.yaml');
        $this->contentPageTests = Yaml::parseFile( __DIR__ . '/../Fixtures/contentPageTests.yaml');
        $this->languages = Yaml::parseFile( __DIR__ . '/../Fixtures/languages.yaml');

        $this->setUpFrontendSite(1);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tm_cloudfront']['cloudfront'] = [
            'distributionIds' => '{"www.example.com":"WWWWWWWWW","en.example.com":"ENENENENENEN","cdn.example.com":"CDNCDNCDNCDN","dk.example.com":"DKDKDKDKDKDK"}',
            'mode' => 'table',
            'region' => 'us',
            'apikey' => 'AAAAAAAAAAAAAAA',
            'apisecret' => 'AAAAAAAAAAAAAAA',
            'version' => 'AAAAAAAAAAAAAAA',
        ];
    }

    /**
     * Helper function to call protected or private methods
     */
    protected function callInaccessibleMethod($object, $name, ...$arguments)
    {
        $reflectionObject = new \ReflectionObject($object);
        $reflectionMethod = $reflectionObject->getMethod($name);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($object, $arguments);
    }

    /**
     * Modify page or content and check if invalidation is created for simple domain
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function modifyTest(): void
    {
        $this->setUpBackendUser(1);
        $this->setUpFrontendSite(1);
        $this->actionService = $this->getActionService();

        foreach ($this->contentPageTests['simple'] as $table => $rows) {
            foreach ($rows as $row) {
                var_dump('Testing ' . $table . ' uid: ' . $row['uid'] . ' simple');
                // Nettoyer les invalidations existantes
                GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getConnectionForTable('tx_tmcloudfront_domain_model_invalidation')
                    ->truncate('tx_tmcloudfront_domain_model_invalidation');
                
                $this->actionService->modifyRecord(
                    $table,
                    $row['uid'],
                    $row['modification']
                );

                $allRecords = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');
                $this->assertCount(count($row['expectedArray']), $allRecords, 'Nombre d’invalidation incorrect');

                foreach ($row['expectedArray'] as $expectedRow) {
                    $this->checkInvalidation($expectedRow);
                }


            }
        }
    }

    /**
     * Modify page or content and check if invalidation is created for multi-domain
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function modifyMultiTest(): void
    {
        $this->setUpBackendUser(1);
        $this->setUpFrontendSite(1,'multiDomain');
        $this->actionService = $this->getActionService();

        foreach ($this->contentPageTests['multiDomain'] as $table => $rows) {
            foreach ($rows as $row) {
                var_dump('Testing ' . $table . ' uid: ' . $row['uid'] . ' multiDomain');
                // Nettoyer les invalidations existantes
                GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getConnectionForTable('tx_tmcloudfront_domain_model_invalidation')
                    ->truncate('tx_tmcloudfront_domain_model_invalidation');
                
                $this->actionService->modifyRecord(
                    $table,
                    $row['uid'],
                    $row['modification']
                );

                $allRecords = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');
                $this->assertCount(count($row['expectedArray']), $allRecords, 'Nombre d’invalidation incorrect');

                foreach ($row['expectedArray'] as $expectedRow) {
                    $this->checkInvalidation($expectedRow);
                }


            }
        }
    }

    /**
     * Test manipulations de fichiers/dossiers
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function fileAndFolderManipulationsTriggerInvalidation(): void
    {
        $this->setUpBackendUser(1);

        // Appeler les tests de manipulation de fichiers/dossiers
        foreach ($this->fileFolderTests as $test => $storages) {
            foreach ($storages as $storageId => $expectedRows) {
                // Nettoyer les invalidations existantes
                GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getConnectionForTable('tx_tmcloudfront_domain_model_invalidation')
                    ->truncate('tx_tmcloudfront_domain_model_invalidation');

                if (method_exists($this, $test)) {
                    $storage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObject($storageId);
                    call_user_func([$this, $test], $storage, $expectedRows);
                }
            }
        }
    }

    public function afterFileReplaced($storage, array $expectedRows): void
    {
        // Créer un fichier texte pour le test
        file_put_contents(
            __DIR__ . '/../Fixtures/sample.txt',
            'contenu texte'
        );
        
        // Créer un sous-dossier 'test_fileReplaced_folder' pour les fichiers du test
        $folder = $storage->hasFolder('test_fileReplaced_folder') ? $storage->getFolder('test_fileReplaced_folder') : $storage->createFolder('test_fileReplaced_folder');
        
        // Ajouter un fichier
        $sourceFile = __DIR__ . '/../Fixtures/sample.txt';
        $storageFile = $storage->addFile($sourceFile, $folder, 'replacedfile.txt');

        // remplacer le fichier
        file_put_contents(
            __DIR__ . '/../Fixtures/sample.txt',
            'contenu texte'
        );
        $storage->replaceFile($storageFile, $sourceFile);

        // Vérifier le nombre des invalidations
        $allRecords = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');
        $this->assertCount(count($expectedRows), $allRecords, 'Nombre d’invalidation incorrect');

        // Vérifier les invalidations
        foreach ($expectedRows as $expectedRow) {
            $this->checkInvalidation($expectedRow);
        }

    }

    public function afterFileMoved($storage, array $expectedRows): void
    {
        // Créer un fichier texte pour le test
        file_put_contents(
            __DIR__ . '/../Fixtures/sample.txt',
            'contenu texte'
        );

        // Créer un dossier "test" et y ajouter le fichier initial
        $folder = $storage->hasFolder('test_fileMoved_folder') ? $storage->getFolder('test_fileMoved_folder') : $storage->createFolder('test_fileMoved_folder');
        $sourceFile = __DIR__ . '/../Fixtures/sample.txt';
        $storageFile = $storage->addFile($sourceFile, $folder, 'movedfile.txt');

        // Créer un dossier "moved" où déplacer le fichier
        $movedFolder = $storage->hasFolder('moved') ? $storage->getFolder('moved') : $storage->createFolder('moved');

        // Déplacer le fichier vers le nouveau dossier
        $storage->moveFile($storageFile, $movedFolder);

        // Vérifier le nombre des invalidations
        $allRecords = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');
        $this->assertCount(count($expectedRows), $allRecords, 'Nombre d’invalidation incorrect pour afterFileMoved');

        // Vérifier les invalidations attendues
        foreach ($expectedRows as $expectedRow) {
            $this->checkInvalidation($expectedRow);
        }
    }

    public function afterFileRenamed($storage, array $expectedRows): void
    {
        // Créer un fichier texte de test
        file_put_contents(
            __DIR__ . '/../Fixtures/sample.txt',
            'contenu texte'
        );

        // Créer un dossier "test" et y ajouter le fichier initial
        $folder = $storage->hasFolder('test_fileRenamed_folder') ? $storage->getFolder('test_fileRenamed_folder') : $storage->createFolder('test_fileRenamed_folder');
        $sourceFile = __DIR__ . '/../Fixtures/sample.txt';
        $storageFile = $storage->addFile($sourceFile, $folder, 'testfile.txt');

        // Renommer le fichier
        $storage->renameFile($storageFile, 'renamedfile.txt');

        // Vérifier le nombre d’invalidations
        $allRecords = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');
        $this->assertCount(count($expectedRows), $allRecords, 'Nombre d’invalidation incorrect pour afterFileRenamed');

        // Vérifier les invalidations attendues
        foreach ($expectedRows as $expectedRow) {
            $this->checkInvalidation($expectedRow);
        }
    }

    public function afterFileContentsSet($storage, array $expectedRows): void
    {
        // Créer un fichier texte initial
        file_put_contents(
            __DIR__ . '/../Fixtures/sample.txt',
            'ancien contenu'
        );

        // Créer un dossier "test" si nécessaire
        $folder = $storage->hasFolder('test_fileContentSet_folder') ? $storage->getFolder('test_fileContentSet_folder') : $storage->createFolder('test_fileContentSet_folder');

        // Ajouter le fichier initial dans le storage
        $sourceFile = __DIR__ . '/../Fixtures/sample.txt';
        $storageFile = $storage->addFile($sourceFile, $folder, 'testfile.txt');

        // Modifier le contenu du fichier directement
        $storage->setFileContents($storageFile, 'nouveau contenu');

        // Vérifier le nombre d’invalidations
        $allRecords = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');
        $this->assertCount(count($expectedRows), $allRecords, 'Nombre d’invalidation incorrect pour afterFileContentsSet');

        // Vérifier les invalidations attendues
        foreach ($expectedRows as $expectedRow) {
            $this->checkInvalidation($expectedRow);
        }
    }

    public function afterFileDeleted($storage, array $expectedRows): void
    {
        // Créer un fichier texte temporaire
        file_put_contents(
            __DIR__ . '/../Fixtures/deleted.txt',
            'contenu à supprimer'
        );

        // Créer un dossier "test" si nécessaire
        $folder = $storage->hasFolder('test_fileDeleted_folder') ? $storage->getFolder('test_fileDeleted_folder') : $storage->createFolder('test_fileDeleted_folder');

        // Ajouter le fichier au storage
        $sourceFile = __DIR__ . '/../Fixtures/deleted.txt';
        $storageFile = $storage->addFile($sourceFile, $folder, 'deleted.txt');

        // Supprimer le fichier
        $storage->deleteFile($storageFile);

        // Vérifier le nombre d’invalidations
        $allRecords = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');
        $this->assertCount(count($expectedRows), $allRecords, 'Nombre d’invalidation incorrect pour afterFileDeleted');

        // Vérifier les invalidations attendues
        foreach ($expectedRows as $expectedRow) {
            $this->checkInvalidation($expectedRow);
        }
    }

    protected function afterFolderMoved(ResourceStorage $storage, array $expectedRows): void
    {
        // 1. Créer un dossier source
        $sourceFolder = $storage->createFolder('testFolder');

        // 2. Créer un dossier cible
        $targetFolder = $storage->hasFolder('movedFolder') ? $storage->getFolder('movedFolder') : $storage->createFolder('movedFolder');

        // 3. Déplacer le dossier
        $storage->moveFolder($sourceFolder, $targetFolder);

        // 4. Vérifier que la table d'invalidation contient le bon nombre de lignes
        $allRecords = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');
        $this->assertCount(count($expectedRows), $allRecords, 'Nombre d’invalidation incorrect pour afterFolderMoved');

        // 5. Vérifier les invalidations attendues
        foreach ($expectedRows as $expectedRow) {
            $this->checkInvalidation($expectedRow);
        }
    }

    protected function afterFolderRenamed(ResourceStorage $storage, array $expectedRows): void
    {
        // 1. Créer un dossier initial
        $folder = $storage->hasFolder('testFolder') ? $storage->getFolder('testFolder') : $storage->createFolder('testFolder');

        // 2. Créer un dossier à l'interieur du dossier initial (pour eviter l'invalidation soit totale)
        $sourceFolder = $storage->createFolder('sourceFolder', $folder);

        // 3. Renommer le dossier
        $storage->renameFolder($sourceFolder, 'renamedFolder');

        // 4. Vérifier que la table d'invalidation contient le bon nombre de lignes
        $allRecords = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');
        $this->assertCount(count($expectedRows), $allRecords, 'Nombre d’invalidation incorrect pour afterFolderRenamed');

        // 5. Vérifier les invalidations attendues
        foreach ($expectedRows as $expectedRow) {
            $this->checkInvalidation($expectedRow);
        }
    }

    protected function afterFolderDeleted(ResourceStorage $storage, array $expectedRows): void
    {
        // 1. Créer un dossier à supprimer
        $folder = $storage->hasFolder('testFolder') ? $storage->getFolder('testFolder') : $storage->createFolder('testFolder');

        // 2. Créer un dossier à l'interieur du dossier initial (pour eviter l'invalidation soit totale)
        $sourceFolder = $storage->createFolder('toDeleteFolder', $folder);

        // 2. Supprimer le dossier
        $storage->deleteFolder($sourceFolder);

        // 3. Vérifier que la table d'invalidation contient le bon nombre de lignes
        $allRecords = $this->getAllRecords('tx_tmcloudfront_domain_model_invalidation');
        $this->assertCount(count($expectedRows), $allRecords, 'Nombre d’invalidation incorrect pour afterFolderDeleted');

        // 4. Vérifier les invalidations attendues
        foreach ($expectedRows as $expectedRow) {
            $this->checkInvalidation($expectedRow);
        }
    }

    protected function checkInvalidation(array $expectedRow): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_tmcloudfront_domain_model_invalidation');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('pathsegment','distributionId')
            ->from('tx_tmcloudfront_domain_model_invalidation')
            ->where(
                $queryBuilder->expr()->eq(
                    'pathsegment',
                    $queryBuilder->createNamedParameter($expectedRow['pathsegment'], Connection::PARAM_STR)
                ),
                $queryBuilder->expr()->eq(
                    'distributionId',
                    $queryBuilder->createNamedParameter($expectedRow['distributionId'], Connection::PARAM_STR)
                )
            );
        $row = $queryBuilder->executeQuery()->fetchAssociative();

        $this->assertNotFalse($row, 'Aucune invalidation trouvée pour ' . $expectedRow['pathsegment'] . ' / ' . $expectedRow['distributionId']);
    }

    /**
     * Site config simple or multi-domain
     */
    protected function setUpFrontendSite(int $pageId, string|null $type = 'simple'): void
    {
        $domain = $type === 'simple' ? 'www.example.com' : '/';
        $configuration = [
            'rootPageId' => $pageId,
            'base' => $domain,
            'languages' => $this->languages[$type],
            'errorHandling' => [],
            'routes' => [],
        ];
        GeneralUtility::mkdir_deep($this->instancePath . '/typo3conf/sites/testing/');
        GeneralUtility::writeFile(
            $this->instancePath . '/typo3conf/sites/testing/config.yaml',
            Yaml::dump($configuration, 99, 2)
        );
        GeneralUtility::makeInstance(CacheManager::class)->getCache('core')->remove('sites-configuration');
    }

    /**
     * @return ActionService
     */
    protected function getActionService()
    {
        return GeneralUtility::makeInstance(ActionService::class);
    }
}