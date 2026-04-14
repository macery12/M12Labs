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
    public function __construct(private ExtensionPanelRebuildService $rebuildService)
    {
    }

    public function uninstall(string $extensionId): void
    {
        $package = ExtensionPackage::query()->with('files')->where('extension_id', $extensionId)->first();
        if (!$package) {
            throw new DisplayException('That extension is not installed through the repository system.');
        }

        $files = $package->files->sortByDesc(fn (ExtensionPackageFile $file) => substr_count($file->path, '/'))->values();
        $rollbackRoot = storage_path('app/extensions/tmp-uninstall/' . Str::uuid()->toString());
        File::ensureDirectoryExists($rollbackRoot);

        $this->assertFilesAreUnmodified($files->all());
        $this->createRollbackSnapshot($files->all(), $rollbackRoot);

        try {
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

            $this->rebuildService->rebuild(sprintf('Uninstall extension %s', $extensionId));

            DB::transaction(function () use ($package, $files, $extensionId) {
                foreach ($files as $file) {
                    if ($file->backup_path && is_file($file->backup_path)) {
                        File::delete($file->backup_path);
                    }
                }

                $package->delete();

                ExtensionConfig::query()->where('extension_id', $extensionId)->update(['enabled' => false]);
            });
        } catch (\Throwable $exception) {
            $this->restoreRollbackSnapshot($files->all(), $rollbackRoot);
            $this->attemptRollbackRebuild($extensionId, 'uninstall rollback');

            if ($exception instanceof DisplayException) {
                throw $exception;
            }

            throw new DisplayException('Failed to uninstall the selected extension package.', $exception);
        } finally {
            File::deleteDirectory($rollbackRoot);
        }
    }

    /**
     * @param array<int, ExtensionPackageFile> $files
     */
    private function assertFilesAreUnmodified(array $files): void
    {
        $modified = [];

        foreach ($files as $file) {
            $targetPath = base_path($file->path);
            if (!is_file($targetPath)) {
                $modified[] = $file->path;

                continue;
            }

            $currentChecksum = hash_file('sha256', $targetPath);
            if ($currentChecksum !== $file->installed_checksum) {
                $modified[] = $file->path;
            }
        }

        if ($modified === []) {
            return;
        }

        $preview = implode(', ', array_slice($modified, 0, 5));
        $suffix = count($modified) > 5 ? ', and more' : '';

        throw new DisplayException(
            sprintf('The extension cannot be uninstalled because these files were modified after installation: %s%s.', $preview, $suffix)
        );
    }

    /**
     * @param array<int, ExtensionPackageFile> $files
     */
    private function createRollbackSnapshot(array $files, string $rollbackRoot): void
    {
        foreach ($files as $file) {
            $targetPath = base_path($file->path);
            if (!is_file($targetPath)) {
                continue;
            }

            $rollbackPath = $rollbackRoot . '/' . $file->path;
            File::ensureDirectoryExists(dirname($rollbackPath));
            File::copy($targetPath, $rollbackPath);
        }
    }

    /**
     * @param array<int, ExtensionPackageFile> $files
     */
    private function restoreRollbackSnapshot(array $files, string $rollbackRoot): void
    {
        foreach ($files as $file) {
            $rollbackPath = $rollbackRoot . '/' . $file->path;
            $targetPath = base_path($file->path);

            if (!is_file($rollbackPath)) {
                continue;
            }

            File::ensureDirectoryExists(dirname($targetPath));
            File::copy($rollbackPath, $targetPath);
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