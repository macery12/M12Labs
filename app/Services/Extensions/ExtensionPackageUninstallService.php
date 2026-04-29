<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionPackageFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ExtensionPackageUninstallService
{
    public function __construct(
        private ExtensionPanelRebuildService $rebuildService,
        private ExtensionOperationLockService $operationLockService,
        private ExtensionFilesystemOwnershipService $ownershipService,
        private ExtensionInstallProgressService $progressService,
        private ExtensionPackageFileService $fileService
    ) {
    }

    public function uninstall(string $extensionId): void
    {
        $this->operationLockService->withinLock('uninstall', $extensionId, function () use ($extensionId) {
            $prepared = null;
            try {
                $prepared = $this->prepareUninstall($extensionId);

                $this->rebuildService->rebuild(
                    sprintf('Uninstall extension %s', $extensionId),
                    function (int $index) use ($extensionId): void {
                        $this->progressService->report(
                            'uninstall',
                            $extensionId,
                            $index === 0 ? 'optimizing' : 'building'
                        );
                    }
                );

                $this->progressService->report('uninstall', $extensionId, 'registering');
                $this->finalizeUninstall($prepared);
                $this->progressService->report('uninstall', $extensionId, 'completed');
            } catch (\Throwable $exception) {
                if ($prepared !== null) {
                    $this->rollbackUninstall($prepared);
                    $this->attemptRollbackRebuild($extensionId, 'uninstall rollback');
                }

                if ($exception instanceof DisplayException) {
                    throw $exception;
                }

                throw new DisplayException('Failed to uninstall the selected extension package.', $exception);
            } finally {
                $this->progressService->clear();
                $this->ownershipService->repairStandardPaths($extensionId);
                if ($prepared !== null) {
                    $this->cleanupPreparedUninstall($prepared);
                }
            }
        });
    }

    /**
     * Prepare an extension uninstall: validate files, snapshot for rollback, and remove files from disk.
     * Does NOT rebuild the panel or modify the database.
     *
     * Used by the batch service to prepare multiple extensions before a single rebuild.
     * After calling this for each extension, call ExtensionPanelRebuildService::rebuild()
     * once, then finalizeUninstall() for each prepared result.
     *
     * @return array<string, mixed> Opaque prepared state; pass to finalizeUninstall() and rollbackUninstall().
     */
    public function prepareUninstall(string $extensionId): array
    {
        $package = ExtensionPackage::query()->with('files')->where('extension_id', $extensionId)->first();
        if (!$package) {
            throw new DisplayException('That extension is not installed through the repository system.');
        }

        $files = $package->files->sortByDesc(fn (ExtensionPackageFile $file) => substr_count($file->path, '/'))->values();
        $rollbackRoot = storage_path('app/extensions/tmp-uninstall/' . Str::uuid()->toString());
        File::ensureDirectoryExists($rollbackRoot);
        $this->ownershipService->repairStandardPaths($extensionId);

        $this->progressService->report('uninstall', $extensionId, 'validating');
        $this->fileService->assertFilesUnmodified($files->all(), 'uninstalled');
        $this->fileService->createRollbackSnapshot($files->all(), $rollbackRoot);
        $this->assertWritableUninstallTargets($files->all());

        try {
            $this->progressService->report('uninstall', $extensionId, 'removing');
            foreach ($files as $file) {
                $targetPath = base_path($file->path);

                if ($file->operation === 'updated') {
                    if (!$file->backup_path || !is_file($file->backup_path)) {
                        throw new DisplayException(sprintf('The backup for "%s" is missing, so the extension cannot be uninstalled safely.', $file->path));
                    }

                    File::ensureDirectoryExists(dirname($targetPath));
                    File::copy($file->backup_path, $targetPath);

                    continue;
                }

                if (is_file($targetPath)) {
                    File::delete($targetPath);
                }
            }

            return [
                'extensionId' => $extensionId,
                'package' => $package,
                'files' => $files,
                'rollbackRoot' => $rollbackRoot,
            ];
        } catch (\Throwable $exception) {
            $this->fileService->restoreRollbackSnapshot($files->all(), $rollbackRoot);
            File::deleteDirectory($rollbackRoot);
            $this->ownershipService->repairStandardPaths($extensionId);

            if ($exception instanceof DisplayException) {
                throw $exception;
            }

            throw new DisplayException('Failed to prepare the extension for uninstallation.', $exception);
        }
    }

    /**
     * Finalize an uninstall prepared via prepareUninstall(): delete the package record from the database.
     * Must be called after the panel has been rebuilt.
     *
     * @param array<string, mixed> $prepared
     */
    public function finalizeUninstall(array $prepared): void
    {
        $package = $prepared['package'];
        $files = $prepared['files'];
        $extensionId = $prepared['extensionId'];

        DB::transaction(function () use ($package, $files, $extensionId) {
            foreach ($files as $file) {
                if ($file->backup_path && is_file($file->backup_path)) {
                    File::delete($file->backup_path);
                }
            }

            $package->delete();

            ExtensionConfig::query()->where('extension_id', $extensionId)->update(['enabled' => false]);
        });
    }

    /**
     * Roll back a prepared uninstall by restoring files from the rollback snapshot.
     *
     * @param array<string, mixed> $prepared
     */
    public function rollbackUninstall(array $prepared): void
    {
        $this->fileService->restoreRollbackSnapshot($prepared['files']->all(), $prepared['rollbackRoot']);
        $this->ownershipService->repairStandardPaths($prepared['extensionId']);
    }

    /**
     * Clean up temp files associated with a prepared uninstall.
     *
     * @param array<string, mixed> $prepared
     */
    public function cleanupPreparedUninstall(array $prepared): void
    {
        if (!empty($prepared['rollbackRoot'])) {
            File::deleteDirectory($prepared['rollbackRoot']);
        }
    }

    /**
     * @param array<int, ExtensionPackageFile> $files
     */
    private function assertWritableUninstallTargets(array $files): void
    {
        foreach ($files as $file) {
            $targetPath = base_path($file->path);

            if ($file->operation === 'updated') {
                $this->ownershipService->ensureWritablePath($targetPath, $file->path);

                continue;
            }

            $this->ownershipService->ensureRemovablePath($targetPath, $file->path);
        }
    }

    private function attemptRollbackRebuild(string $extensionId, string $reason): void
    {
        try {
            $this->rebuildService->rebuild(sprintf('%s for %s', $reason, $extensionId));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
