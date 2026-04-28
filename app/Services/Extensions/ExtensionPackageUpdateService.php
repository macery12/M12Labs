<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionPackageFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

class ExtensionPackageUpdateService
{
    private const MANIFEST_FILENAME = 'm12labs-extension.json';
    public const PACKAGE_ARTIFACT_FILENAME = 'package.M12LabsExtension';

    public function __construct(
        private ExtensionCatalogService $catalogService,
        private ExtensionPanelRebuildService $rebuildService,
        private ExtensionOperationLockService $operationLockService,
        private ExtensionFilesystemOwnershipService $ownershipService,
        private ExtensionInstallProgressService $progressService
    ) {
    }

    /**
     * Update an extension from a configured repository.
     */
    public function update(string $extensionId, int $repositoryId, ?string $version = null): ExtensionPackage
    {
        return $this->operationLockService->withinLock('update', $extensionId, function () use ($extensionId, $repositoryId, $version) {
            $package = $this->catalogService->findRepositoryPackage($extensionId, $repositoryId, $version);
            $release = $package['latestRelease'];

            return $this->updateFromSource(
                archiveLocation: $release['archiveUrl'],
                extensionId: $extensionId,
                expectedVersion: $release['version'],
                expectedArchiveChecksum: $release['archiveChecksum'],
                compatiblePanelVersions: $release['compatiblePanelVersions'] ?? [],
                sourceRepositoryId: $package['repository']->id,
                sourceRepositoryName: $package['repository']->name,
                sourceRegistryUrl: $package['repository']->manifest_url,
                sourceArchiveUrl: $release['archiveUrl'],
                fallbackPackageMetadata: $package,
            );
        });
    }

    /**
     * Update an extension from a local .M12LabsExtension archive.
     */
    public function updateFromArchive(string $archivePath, ?string $sourceLabel = null): ExtensionPackage
    {
        $resolvedPath = $this->resolveLocalArchivePath($archivePath);
        $this->assertSupportedArchiveArtifact($resolvedPath);

        return $this->operationLockService->withinLock('update', basename($resolvedPath), function () use ($resolvedPath, $sourceLabel) {
            return $this->updateFromSource(
                archiveLocation: $resolvedPath,
                extensionId: null,
                expectedVersion: null,
                expectedArchiveChecksum: null,
                compatiblePanelVersions: [],
                sourceRepositoryId: null,
                sourceRepositoryName: $sourceLabel ?: 'Manual package file',
                sourceRegistryUrl: null,
                sourceArchiveUrl: 'file://' . $resolvedPath,
                fallbackPackageMetadata: [],
            );
        });
    }

    /**
     * @param array<string, mixed> $fallbackPackageMetadata
     * @param array<int, string> $compatiblePanelVersions
     */
    private function updateFromSource(
        string $archiveLocation,
        ?string $extensionId,
        ?string $expectedVersion,
        ?string $expectedArchiveChecksum,
        array $compatiblePanelVersions,
        ?int $sourceRepositoryId,
        ?string $sourceRepositoryName,
        ?string $sourceRegistryUrl,
        string $sourceArchiveUrl,
        array $fallbackPackageMetadata
    ): ExtensionPackage {
        $tempRoot = storage_path('app/extensions/tmp/' . Str::uuid()->toString());
        $archivePath = $tempRoot . '/' . self::PACKAGE_ARTIFACT_FILENAME;
        $extractPath = $tempRoot . '/extract';
        $rollbackRoot = storage_path('app/extensions/tmp-update/' . Str::uuid()->toString());
        $resolvedExtensionId = $extensionId;
        $existingPackage = null;

        File::ensureDirectoryExists($tempRoot);
        File::ensureDirectoryExists($extractPath);
        File::ensureDirectoryExists($rollbackRoot);

        try {
            $this->progressService->report('update', $resolvedExtensionId ?? 'unknown', 'downloading');
            $this->downloadArchive($archiveLocation, $archivePath);

            $this->progressService->report('update', $resolvedExtensionId ?? 'unknown', 'extracting');
            $archiveChecksum = hash_file('sha256', $archivePath);
            if ($expectedArchiveChecksum !== null) {
                $this->verifyChecksum($archivePath, $expectedArchiveChecksum, 'archive');
            }
            $this->extractArchive($archivePath, $extractPath);

            $this->progressService->report('update', $resolvedExtensionId ?? 'unknown', 'validating');
            $manifest = $this->readPackageManifest($extractPath);
            $normalizedManifest = $this->normalizeManifest($manifest, $extensionId, $expectedVersion);
            $resolvedExtensionId = (string) Arr::get($normalizedManifest, 'extension.id');

            $existingPackage = ExtensionPackage::query()
                ->with('files')
                ->where('extension_id', $resolvedExtensionId)
                ->first();

            if (!$existingPackage) {
                throw new DisplayException('This extension is not currently installed. Use the install command to install it first.');
            }

            $this->assertCompatiblePanelVersions($compatiblePanelVersions);
            $this->assertCompatiblePanelVersions(Arr::get($normalizedManifest, 'compatiblePanelVersions', []));
            $this->ownershipService->repairStandardPaths($resolvedExtensionId);

            // Verify installed files have not been externally modified before we touch anything.
            $this->assertInstalledFilesUnmodified($existingPackage->files->all());

            // Snapshot the current state of all installed files for rollback.
            $this->createRollbackSnapshot($existingPackage->files->all(), $rollbackRoot);

            $newBackupRoot = storage_path('app/extensions/backups/' . $resolvedExtensionId . '/' . Str::uuid()->toString());

            // Build plans for the new file set, inheriting backups from the current install.
            $newFilePlans = $this->prepareUpdateFilePlans(
                $extractPath,
                $normalizedManifest,
                $newBackupRoot,
                $resolvedExtensionId,
                $existingPackage
            );

            // Files present in the old package but absent from the new set must be cleaned up.
            $newFilePaths = array_column($newFilePlans, 'path');
            /** @var array<int, ExtensionPackageFile> $oldOnlyFiles */
            $oldOnlyFiles = $existingPackage->files
                ->filter(fn (ExtensionPackageFile $f) => !in_array($f->path, $newFilePaths, true))
                ->values()
                ->all();

            $this->assertWritableUpdateTargets($newFilePlans, $oldOnlyFiles);

            // Remove / restore old-only files before copying new content.
            $this->progressService->report('update', $resolvedExtensionId, 'removing');
            foreach ($oldOnlyFiles as $oldFile) {
                $targetPath = base_path($oldFile->path);

                if ($oldFile->operation === 'updated') {
                    // Restore the original pre-extension file from the backup.
                    if ($oldFile->backup_path && is_file($oldFile->backup_path)) {
                        File::ensureDirectoryExists(dirname($targetPath));
                        File::copy($oldFile->backup_path, $targetPath);
                    }
                } elseif (is_file($targetPath)) {
                    File::delete($targetPath);
                }
            }

            // Copy new files to their target locations.
            $this->progressService->report('update', $resolvedExtensionId, 'copying');
            foreach ($newFilePlans as $plan) {
                File::ensureDirectoryExists(dirname($plan['targetPath']));
                File::copy($plan['sourcePath'], $plan['targetPath']);
            }

            // Rebuild the panel once after all filesystem changes.
            $this->rebuildService->rebuild(
                sprintf('Update extension %s', $resolvedExtensionId),
                function (int $index) use ($resolvedExtensionId): void {
                    $this->progressService->report(
                        'update',
                        $resolvedExtensionId,
                        $index === 0 ? 'optimizing' : 'building'
                    );
                }
            );

            $this->progressService->report('update', $resolvedExtensionId, 'registering');
            DB::transaction(function () use (
                $archiveChecksum,
                $existingPackage,
                $fallbackPackageMetadata,
                $newFilePlans,
                $normalizedManifest,
                $oldOnlyFiles,
                $resolvedExtensionId,
                $sourceArchiveUrl,
                $sourceRegistryUrl,
                $sourceRepositoryId,
                $sourceRepositoryName
            ) {
                // Replace all tracked file records for this package.
                ExtensionPackageFile::query()
                    ->where('extension_package_id', $existingPackage->id)
                    ->delete();

                // Update the package metadata to the new version.
                $existingPackage->update([
                    'package_id'           => Arr::get($normalizedManifest, 'package.id', Arr::get($fallbackPackageMetadata, 'id', $resolvedExtensionId)),
                    'name'                 => Arr::get($normalizedManifest, 'extension.name', Arr::get($fallbackPackageMetadata, 'name', $resolvedExtensionId)),
                    'description'          => Arr::get($normalizedManifest, 'extension.description', Arr::get($fallbackPackageMetadata, 'description', '')),
                    'author'               => Arr::get($normalizedManifest, 'extension.author', Arr::get($fallbackPackageMetadata, 'author', 'M12Labs')),
                    'icon'                 => Arr::get($normalizedManifest, 'extension.icon', Arr::get($fallbackPackageMetadata, 'icon', 'puzzle')),
                    'route'                => Arr::get($normalizedManifest, 'extension.route', Arr::get($fallbackPackageMetadata, 'route', $resolvedExtensionId)),
                    'installed_version'    => Arr::get($normalizedManifest, 'package.version'),
                    'source_repository_id' => $sourceRepositoryId ?? $existingPackage->source_repository_id,
                    'source_repository_name' => $sourceRepositoryName ?? $existingPackage->source_repository_name,
                    'source_registry_url'  => $sourceRegistryUrl ?? $existingPackage->source_registry_url,
                    'source_archive_url'   => $sourceArchiveUrl,
                    'package_checksum'     => is_string($archiveChecksum) ? $archiveChecksum : null,
                    'manifest'             => $normalizedManifest,
                    'installed_at'         => now(),
                ]);

                // Record the new file set.
                foreach ($newFilePlans as $plan) {
                    ExtensionPackageFile::query()->create([
                        'extension_package_id' => $existingPackage->id,
                        'path'                 => $plan['path'],
                        'operation'            => $plan['operation'],
                        'installed_checksum'   => $plan['checksum'],
                        'backup_path'          => $plan['backupPath'],
                        'backup_checksum'      => $plan['backupChecksum'],
                    ]);
                }

                // Clean up on-disk backups for old-only 'updated' files; the
                // originals have already been restored to their live paths.
                foreach ($oldOnlyFiles as $oldFile) {
                    if ($oldFile->operation === 'updated' && $oldFile->backup_path && is_file($oldFile->backup_path)) {
                        File::delete($oldFile->backup_path);
                    }
                }
            });

            $this->progressService->report('update', $resolvedExtensionId, 'completed');

            return $existingPackage->fresh(['repository', 'files']);
        } catch (\Throwable $exception) {
            // Restore the previous installation state from the rollback snapshot.
            if ($existingPackage) {
                $this->restoreRollbackSnapshot($existingPackage->files->all(), $rollbackRoot);
                $this->attemptRollbackRebuild($resolvedExtensionId ?? 'extension', 'update rollback');
            }

            if ($exception instanceof DisplayException) {
                throw $exception;
            }

            throw new DisplayException('Failed to update the selected extension package.', $exception);
        } finally {
            $this->progressService->clear();
            $this->ownershipService->repairStandardPaths($resolvedExtensionId);
            File::deleteDirectory($tempRoot);
            File::deleteDirectory($rollbackRoot);
        }
    }

    /**
     * Build the file plans for the new version, inheriting pre-extension backups
     * from the current install for any paths that were already tracked.
     *
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    private function prepareUpdateFilePlans(
        string $extractPath,
        array $manifest,
        string $newBackupRoot,
        string $extensionId,
        ExtensionPackage $existingPackage
    ): array {
        $plans = [];
        $files = Arr::get($manifest, 'files', []);
        if (!is_array($files) || $files === []) {
            throw new DisplayException('The extension package manifest does not declare any installable files.');
        }

        /** @var array<string, ExtensionPackageFile> $oldFilesByPath */
        $oldFilesByPath = $existingPackage->files->keyBy('path')->all();

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $path = $this->normalizeTargetPath((string) ($file['path'] ?? ''), $extensionId);
            $checksum = trim((string) ($file['sha256'] ?? ''));

            if ($path === '' || $checksum === '') {
                throw new DisplayException('The extension package manifest contains an invalid file entry.');
            }

            $sourcePath = $extractPath . '/' . $path;
            if (!is_file($sourcePath)) {
                throw new DisplayException(sprintf('The extension package is missing "%s".', $path));
            }

            $this->verifyChecksum($sourcePath, $checksum, sprintf('file "%s"', $path));

            // Ensure the path is not owned by a different extension.
            if (ExtensionPackageFile::query()
                ->where('path', $path)
                ->where('extension_package_id', '!=', $existingPackage->id)
                ->exists()
            ) {
                throw new DisplayException(sprintf('The path "%s" is already managed by another installed extension.', $path));
            }

            $targetPath = base_path($path);
            $backupPath = null;
            $backupChecksum = null;
            $operation = 'created';

            $oldFile = $oldFilesByPath[$path] ?? null;

            if ($oldFile !== null) {
                if ($oldFile->operation === 'updated') {
                    // Inherit the original pre-extension backup so a future
                    // uninstall can still restore the original file.
                    $operation = 'updated';
                    $backupPath = $oldFile->backup_path;
                    $backupChecksum = $oldFile->backup_checksum;
                } else {
                    // File was created by our extension; it remains ours.
                    $operation = 'created';
                }
            } elseif (is_file($targetPath)) {
                // New path for this version, but a file already exists there
                // (not tracked by us); back it up so it can be restored.
                $operation = 'updated';
                $backupPath = $newBackupRoot . '/' . $path;
                File::ensureDirectoryExists(dirname($backupPath));
                File::copy($targetPath, $backupPath);
                $backupChecksum = hash_file('sha256', $backupPath);
            }

            $plans[] = [
                'path'          => $path,
                'sourcePath'    => $sourcePath,
                'targetPath'    => $targetPath,
                'operation'     => $operation,
                'checksum'      => $checksum,
                'backupPath'    => $backupPath,
                'backupChecksum' => $backupChecksum,
            ];
        }

        return $plans;
    }

    /**
     * Fail if any tracked file has been externally modified since it was installed.
     *
     * @param array<int, ExtensionPackageFile> $files
     */
    private function assertInstalledFilesUnmodified(array $files): void
    {
        $modified = [];

        foreach ($files as $file) {
            $targetPath = base_path($file->path);
            if (!is_file($targetPath)) {
                $modified[] = $file->path;
                continue;
            }

            if (hash_file('sha256', $targetPath) !== $file->installed_checksum) {
                $modified[] = $file->path;
            }
        }

        if ($modified === []) {
            return;
        }

        $preview = implode(', ', array_slice($modified, 0, 5));
        $suffix = count($modified) > 5 ? ', and more' : '';

        throw new DisplayException(
            sprintf('The extension cannot be updated because these files were modified after installation: %s%s.', $preview, $suffix)
        );
    }

    /**
     * Snapshot all currently installed files to a temporary directory.
     *
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
     * Restore all tracked files to the state captured in the rollback snapshot.
     *
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

            $this->ownershipService->ensureWritablePath($targetPath, $file->path);
            File::ensureDirectoryExists(dirname($targetPath));
            File::copy($rollbackPath, $targetPath);
        }
    }

    /**
     * Assert that all target paths are writable before making any changes.
     *
     * @param array<int, array<string, mixed>> $newFilePlans
     * @param array<int, ExtensionPackageFile> $oldOnlyFiles
     */
    private function assertWritableUpdateTargets(array $newFilePlans, array $oldOnlyFiles): void
    {
        foreach ($newFilePlans as $plan) {
            $this->ownershipService->ensureWritablePath($plan['targetPath'], $plan['path']);
        }

        foreach ($oldOnlyFiles as $oldFile) {
            $targetPath = base_path($oldFile->path);

            if ($oldFile->operation === 'updated') {
                $this->ownershipService->ensureWritablePath($targetPath, $oldFile->path);
            } else {
                $this->ownershipService->ensureRemovablePath($targetPath, $oldFile->path);
            }
        }
    }

    private function downloadArchive(string $location, string $destination): void
    {
        if (Str::startsWith($location, ['http://', 'https://'])) {
            $response = Http::timeout(120)->withOptions(['sink' => $destination])->get($location);
            if (!$response->successful()) {
                throw new DisplayException(sprintf('Unable to download extension archive from "%s".', $location));
            }

            return;
        }

        $sourcePath = Str::startsWith($location, 'file://') ? rawurldecode(substr($location, 7)) : $location;
        if (!is_file($sourcePath)) {
            throw new DisplayException(sprintf('Extension archive "%s" was not found.', $sourcePath));
        }

        File::copy($sourcePath, $destination);
    }

    private function verifyChecksum(string $path, string $expectedChecksum, string $label): void
    {
        $actualChecksum = hash_file('sha256', $path);
        if ($actualChecksum !== $expectedChecksum) {
            throw new DisplayException(sprintf('The %s checksum did not match the manifest.', $label));
        }
    }

    private function extractArchive(string $archivePath, string $extractPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new DisplayException('The downloaded extension archive could not be opened.');
        }

        if (!$zip->extractTo($extractPath)) {
            $zip->close();
            throw new DisplayException('The downloaded extension archive could not be extracted.');
        }

        $zip->close();
    }

    /**
     * @return array<string, mixed>
     */
    private function readPackageManifest(string $extractPath): array
    {
        $manifestPath = $extractPath . '/' . self::MANIFEST_FILENAME;
        if (!is_file($manifestPath)) {
            throw new DisplayException('The extension archive did not include an m12labs-extension.json manifest.');
        }

        $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new DisplayException('The extension package manifest is invalid.');
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function normalizeManifest(array $manifest, ?string $expectedExtensionId = null, ?string $expectedVersion = null): array
    {
        $extensionId = trim((string) Arr::get($manifest, 'extension.id', ''));
        $version = trim((string) Arr::get($manifest, 'package.version', ''));

        if ($extensionId === '' || $version === '') {
            throw new DisplayException('The extension package manifest is missing required metadata.');
        }

        if ($expectedExtensionId !== null && $extensionId !== $expectedExtensionId) {
            throw new DisplayException('The downloaded package does not match the requested extension id.');
        }

        if ($expectedVersion !== null && $version !== $expectedVersion) {
            throw new DisplayException('The downloaded package version does not match the repository manifest.');
        }

        return $manifest;
    }

    /**
     * @param array<int, string> $versions
     */
    private function assertCompatiblePanelVersions(array $versions): void
    {
        $versions = array_values(array_filter($versions, 'is_string'));
        if ($versions === []) {
            return;
        }

        $currentVersion = (string) config('app.version');
        if (!in_array($currentVersion, $versions, true)) {
            throw new DisplayException(sprintf(
                'This extension package supports M12Labs panel versions %s. The current panel version is %s.',
                implode(', ', $versions),
                $currentVersion
            ));
        }
    }

    private function normalizeTargetPath(string $path, string $extensionId): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $normalized = trim($normalized, '/');

        if ($normalized === '' || Str::contains($normalized, ['../', '..\\']) || Str::startsWith($normalized, '/')) {
            throw new DisplayException('The extension package includes an unsafe target path.');
        }

        $allowedPrefixes = [
            sprintf('app/Extensions/Packages/%s/', $extensionId),
            sprintf('resources/scripts/extensions/packages/%s/', $extensionId),
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (Str::startsWith($normalized, $prefix)) {
                return $normalized;
            }
        }

        throw new DisplayException(sprintf('The package target path "%s" is not allowed by M12Labs.', $normalized));
    }

    private function resolveLocalArchivePath(string $archivePath): string
    {
        $archivePath = trim($archivePath);
        if ($archivePath === '') {
            throw new DisplayException('Provide a path to a local .M12LabsExtension package file.');
        }

        if (Str::startsWith($archivePath, 'file://')) {
            $archivePath = rawurldecode(substr($archivePath, 7));
        }

        $candidates = [$archivePath];
        if (!Str::startsWith($archivePath, '/')) {
            $candidates[] = base_path($archivePath);
        }

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved && is_file($resolved)) {
                return $resolved;
            }
        }

        throw new DisplayException(sprintf('The extension package file "%s" was not found.', $archivePath));
    }

    private function assertSupportedArchiveArtifact(string $archivePath): void
    {
        $normalizedPath = Str::lower($archivePath);
        if (Str::endsWith($normalizedPath, ['.m12labsextension', '.zip'])) {
            return;
        }

        throw new DisplayException('Manual updates expect a .M12LabsExtension package file. Legacy .zip artifacts are still supported for compatibility.');
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
