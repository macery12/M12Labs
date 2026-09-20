<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Models\ExtensionPackageFile;
use Illuminate\Database\Eloquent\Collection;
use Everest\Services\Extensions\ExtensionCatalogService;
use Everest\Services\Extensions\ExtensionJobDrainService;
use Everest\Services\Extensions\ExtensionMigrationService;
use Everest\Services\Extensions\ExtensionSignatureService;
use Everest\Services\Extensions\ExtensionPackageFileService;
use Everest\Services\Extensions\ExtensionPermissionRegistry;
use Everest\Services\Extensions\ExtensionRequirementService;
use Everest\Services\Extensions\ExtensionPageManifestService;
use Everest\Services\Extensions\ExtensionPanelRebuildService;
use Everest\Services\Extensions\ExtensionOperationLockService;
use Everest\Services\Extensions\ExtensionPackageUpdateService;
use Everest\Services\Extensions\ExtensionFrontendImportScanner;
use Everest\Services\Extensions\ExtensionInstallProgressService;
use Everest\Services\Extensions\ExtensionPackageArtifactService;
use Everest\Services\Extensions\ExtensionPackageIntegrityService;
use Everest\Services\Extensions\ExtensionFilesystemOwnershipService;

class ExtensionPackageUpdateRecoveryTest extends TestCase
{
    private string $workspace;

    public function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir() . '/extension-update-recovery-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function testRollbackRemovesCreatedPathsAndRestoresUntrackedPaths(): void
    {
        $createdPath = $this->workspace . '/created.php';
        $replacedPath = $this->workspace . '/replaced.php';
        $backupRoot = $this->workspace . '/new-backups';
        $backupPath = $backupRoot . '/replaced.php';
        $rollbackRoot = $this->workspace . '/old-snapshot';
        File::ensureDirectoryExists($backupRoot);
        File::ensureDirectoryExists($rollbackRoot);
        File::put($createdPath, 'new created file');
        File::put($replacedPath, 'new replacement');
        File::put($backupPath, 'original untracked file');

        $package = new ExtensionPackage();
        $package->setRelation('files', new Collection());

        $ownership = \Mockery::mock(ExtensionFilesystemOwnershipService::class);
        $ownership->expects('ensureWritablePath')->once()->with($replacedPath, 'replaced.php');
        $ownership->expects('ensureRemovablePath')->once()->with($createdPath, 'created.php');
        $ownership->expects('repairStandardPaths')->once()->with('demo');
        $fileService = \Mockery::mock(ExtensionPackageFileService::class);
        $fileService->expects('restoreRollbackSnapshot')->once()->with([], $rollbackRoot);
        $service = $this->service($ownership, $fileService);

        $service->rollbackUpdate([
            'extensionId' => 'demo',
            'appliedMigrations' => [],
            'existingPackage' => $package,
            'newFilePlans' => [
                [
                    'path' => 'created.php',
                    'targetPath' => $createdPath,
                    'operation' => 'created',
                    'backupPath' => null,
                ],
                [
                    'path' => 'replaced.php',
                    'targetPath' => $replacedPath,
                    'operation' => 'updated',
                    'backupPath' => $backupPath,
                ],
            ],
            'rollbackRoot' => $rollbackRoot,
            'newBackupRoot' => $backupRoot,
        ]);

        $this->assertFileDoesNotExist($createdPath);
        $this->assertSame('original untracked file', File::get($replacedPath));
        $this->assertDirectoryDoesNotExist($backupRoot);
    }

    public function testObsoleteBackupIsRemovedOnlyByCompletionStep(): void
    {
        $backupPath = $this->workspace . '/old-version/original.php';
        File::ensureDirectoryExists(dirname($backupPath));
        File::put($backupPath, 'original');

        $service = $this->service();
        $oldFile = new ExtensionPackageFile([
            'operation' => 'updated',
            'backup_path' => $backupPath,
        ]);

        $this->assertFileExists($backupPath);
        $service->completeUpdate(['oldOnlyFiles' => [$oldFile]]);
        $this->assertFileDoesNotExist($backupPath);
    }

    private function service(
        ?ExtensionFilesystemOwnershipService $ownership = null,
        ?ExtensionPackageFileService $fileService = null,
    ): ExtensionPackageUpdateService {
        return new ExtensionPackageUpdateService(
            \Mockery::mock(ExtensionCatalogService::class),
            \Mockery::mock(ExtensionPanelRebuildService::class),
            \Mockery::mock(ExtensionOperationLockService::class),
            $ownership ?? \Mockery::mock(ExtensionFilesystemOwnershipService::class),
            \Mockery::mock(ExtensionInstallProgressService::class),
            \Mockery::mock(ExtensionPackageArtifactService::class),
            $fileService ?? \Mockery::mock(ExtensionPackageFileService::class),
            \Mockery::mock(ExtensionMigrationService::class),
            \Mockery::mock(ExtensionPackageIntegrityService::class),
            \Mockery::mock(ExtensionPermissionRegistry::class),
            \Mockery::mock(ExtensionJobDrainService::class),
            \Mockery::mock(ExtensionPageManifestService::class),
            \Mockery::mock(ExtensionSignatureService::class),
            \Mockery::mock(ExtensionFrontendImportScanner::class),
            \Mockery::mock(ExtensionRequirementService::class),
        );
    }
}
