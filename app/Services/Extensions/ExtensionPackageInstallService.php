<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionPackageFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExtensionPackageInstallService
{
    public function __construct(
        private ExtensionCatalogService $catalogService,
        private ExtensionPanelRebuildService $rebuildService,
        private ExtensionOperationLockService $operationLockService,
        private ExtensionFilesystemOwnershipService $ownershipService,
        private ExtensionInstallProgressService $progressService,
        private ExtensionPackageArtifactService $artifactService,
        private ExtensionSecurityScanner $scanner
    ) {
    }

    public function install(string $extensionId, int $repositoryId, ?string $version = null): ExtensionPackage
    {
        return $this->operationLockService->withinLock('install', $extensionId, function () use ($extensionId, $repositoryId, $version) {
            $prepared = null;
            try {
                $prepared = $this->prepareInstall($extensionId, $repositoryId, $version);

                $this->rebuildService->rebuild(
                    sprintf('Install extension %s', $prepared['extensionId']),
                    function (int $index) use ($prepared): void {
                        $this->progressService->report(
                            'install',
                            $prepared['extensionId'],
                            $index === 0 ? 'optimizing' : 'building'
                        );
                    }
                );

                $this->progressService->report('install', $prepared['extensionId'], 'registering');
                $packageModel = $this->finalizeInstall($prepared);
                $this->progressService->report('install', $prepared['extensionId'], 'completed');

                return $packageModel->fresh(['repository', 'files']);
            } catch (\Throwable $exception) {
                if ($prepared !== null) {
                    $this->rollbackInstall($prepared);
                    $this->attemptRollbackRebuild($prepared['extensionId'], 'install rollback');
                }

                if ($exception instanceof DisplayException) {
                    throw $exception;
                }

                throw new DisplayException('Failed to install the selected extension package.', $exception);
            } finally {
                $this->progressService->clear();
                if ($prepared !== null) {
                    $this->cleanupPreparedInstall($prepared);
                }
            }
        });
    }

    public function installFromArchive(string $archivePath, ?string $sourceLabel = null, bool $skipScan = false): ExtensionPackage
    {
        $resolvedArchivePath = $this->artifactService->resolveArchivePath($archivePath);
        $this->assertSupportedArchiveArtifact($resolvedArchivePath);

        return $this->operationLockService->withinLock('install', basename($resolvedArchivePath), function () use ($resolvedArchivePath, $sourceLabel, $skipScan) {
            $prepared = null;
            try {
                $prepared = $this->performInstallFileOps(
                    archiveLocation: $resolvedArchivePath,
                    expectedExtensionId: null,
                    expectedVersion: null,
                    expectedArchiveChecksum: null,
                    compatiblePanelVersions: [],
                    sourceRepositoryId: null,
                    sourceRepositoryName: $sourceLabel ?: 'Manual package file',
                    sourceRegistryUrl: null,
                    sourceArchiveUrl: 'file://' . $resolvedArchivePath,
                    fallbackPackageMetadata: [],
                    skipScan: $skipScan,
                );

                $this->rebuildService->rebuild(
                    sprintf('Install extension %s', $prepared['extensionId']),
                    function (int $index) use ($prepared): void {
                        $this->progressService->report(
                            'install',
                            $prepared['extensionId'],
                            $index === 0 ? 'optimizing' : 'building'
                        );
                    }
                );

                $this->progressService->report('install', $prepared['extensionId'], 'registering');
                $packageModel = $this->finalizeInstall($prepared);
                $this->progressService->report('install', $prepared['extensionId'], 'completed');

                return $packageModel->fresh(['repository', 'files']);
            } catch (\Throwable $exception) {
                if ($prepared !== null) {
                    $this->rollbackInstall($prepared);
                    $this->attemptRollbackRebuild($prepared['extensionId'], 'install rollback');
                }

                if ($exception instanceof DisplayException) {
                    throw $exception;
                }

                throw new DisplayException('Failed to install the selected extension package.', $exception);
            } finally {
                $this->progressService->clear();
                if ($prepared !== null) {
                    $this->cleanupPreparedInstall($prepared);
                }
            }
        });
    }

    /**
     * Prepare an extension install: download, extract, validate, and copy files into place.
     * Does NOT rebuild the panel or write to the database.
     *
     * Used by the batch service to prepare multiple extensions before a single rebuild.
     * After calling this for each extension, call ExtensionPanelRebuildService::rebuild()
     * once, then finalizeInstall() for each prepared result.
     *
     * @return array<string, mixed> Opaque prepared state; pass to finalizeInstall() and rollbackInstall().
     */
    public function prepareInstall(string $extensionId, int $repositoryId, ?string $version = null): array
    {
        $package = $this->catalogService->findRepositoryPackage($extensionId, $repositoryId, $version);
        $release = $package['latestRelease'];

        return $this->performInstallFileOps(
            archiveLocation: $release['archiveUrl'],
            expectedExtensionId: $extensionId,
            expectedVersion: $release['version'],
            expectedArchiveChecksum: $release['archiveChecksum'],
            compatiblePanelVersions: $release['compatiblePanelVersions'] ?? [],
            sourceRepositoryId: $package['repository']->id,
            sourceRepositoryName: $package['repository']->name,
            sourceRegistryUrl: $package['repository']->manifest_url,
            sourceArchiveUrl: $release['archiveUrl'],
            fallbackPackageMetadata: $package,
        );
    }

    /**
     * Finalize an install prepared via prepareInstall(): write the package record to the database.
     * Must be called after the panel has been rebuilt.
     *
     * @param array<string, mixed> $prepared
     */
    public function finalizeInstall(array $prepared): ExtensionPackage
    {
        return DB::transaction(function () use ($prepared) {
            return $this->persistInstalledPackage(
                extensionId: $prepared['extensionId'],
                normalizedManifest: $prepared['normalizedManifest'],
                fallbackPackageMetadata: $prepared['fallbackPackageMetadata'],
                filePlans: $prepared['filePlans'],
                sourceRepositoryId: $prepared['sourceRepositoryId'],
                sourceRepositoryName: $prepared['sourceRepositoryName'],
                sourceRegistryUrl: $prepared['sourceRegistryUrl'],
                sourceArchiveUrl: $prepared['sourceArchiveUrl'],
                archiveChecksum: $prepared['archiveChecksum'],
            );
        });
    }

    /**
     * Roll back a prepared install by reverting copied files to their pre-install state.
     *
     * @param array<string, mixed> $prepared
     */
    public function rollbackInstall(array $prepared): void
    {
        $this->rollbackAppliedFiles($prepared['appliedFiles'] ?? []);
        $this->ownershipService->repairStandardPaths($prepared['extensionId'] ?? null);
    }

    /**
     * Clean up temp files associated with a prepared install.
     *
     * @param array<string, mixed> $prepared
     */
    public function cleanupPreparedInstall(array $prepared): void
    {
        if (!empty($prepared['tempRoot'])) {
            File::deleteDirectory($prepared['tempRoot']);
        }
    }

    /**
     * Perform the file-operations phase of an install (download → extract → validate → copy).
     * Returns the prepared state needed to finalize or roll back.
     *
     * @param array<string, mixed> $fallbackPackageMetadata
     * @param array<int, string> $compatiblePanelVersions
     * @return array<string, mixed>
     */
    private function performInstallFileOps(
        string $archiveLocation,
        ?string $expectedExtensionId,
        ?string $expectedVersion,
        ?string $expectedArchiveChecksum,
        array $compatiblePanelVersions,
        ?int $sourceRepositoryId,
        ?string $sourceRepositoryName,
        ?string $sourceRegistryUrl,
        string $sourceArchiveUrl,
        array $fallbackPackageMetadata,
        bool $skipScan = false
    ): array {
        $tempRoot = storage_path('app/extensions/tmp/' . Str::uuid()->toString());
        $archivePath = $tempRoot . '/' . ExtensionPackageArtifactService::PACKAGE_ARTIFACT_FILENAME;
        $extractPath = $tempRoot . '/extract';
        $appliedFiles = [];
        $resolvedExtensionId = $expectedExtensionId;

        File::ensureDirectoryExists($tempRoot);
        File::ensureDirectoryExists($extractPath);

        try {
            $this->progressService->report('install', $resolvedExtensionId ?? 'unknown', 'downloading');
            $this->artifactService->downloadArchive($archiveLocation, $archivePath);

            // Security scan runs on the downloaded archive before extraction.
            if (!$skipScan) {
                $this->progressService->report('install', $resolvedExtensionId ?? 'unknown', 'scanning');
                $this->runArchiveScan($archivePath, $resolvedExtensionId ?? 'unknown', 'install');
            }

            $this->progressService->report('install', $resolvedExtensionId ?? 'unknown', 'extracting');
            $archiveChecksum = hash_file('sha256', $archivePath);
            if ($expectedArchiveChecksum !== null) {
                $this->artifactService->verifyChecksum($archivePath, $expectedArchiveChecksum, 'archive');
            }
            $this->artifactService->extractArchive($archivePath, $extractPath);

            $this->progressService->report('install', $resolvedExtensionId ?? 'unknown', 'validating');
            $manifest = $this->artifactService->readPackageManifest($extractPath);
            $normalizedManifest = $this->artifactService->normalizeManifest($manifest, $expectedExtensionId, $expectedVersion);
            $extensionId = (string) Arr::get($normalizedManifest, 'extension.id');
            $resolvedExtensionId = $extensionId;
            $backupRoot = storage_path('app/extensions/backups/' . $extensionId . '/' . Str::uuid()->toString());

            $this->assertExtensionNotInstalled($extensionId);
            $this->artifactService->assertCompatiblePanelVersions($compatiblePanelVersions);
            $this->artifactService->assertCompatiblePanelVersions(Arr::get($normalizedManifest, 'compatiblePanelVersions', []));
            $this->ownershipService->repairStandardPaths($extensionId);

            $filePlans = $this->prepareFilePlans($extractPath, $normalizedManifest, $backupRoot, $extensionId);
            $this->assertWritableInstallTargets($filePlans);

            $this->progressService->report('install', $extensionId, 'copying');
            foreach ($filePlans as $plan) {
                File::ensureDirectoryExists(dirname($plan['targetPath']));
                File::copy($plan['sourcePath'], $plan['targetPath']);
                $appliedFiles[] = $plan;
            }

            return [
                'extensionId' => $extensionId,
                'normalizedManifest' => $normalizedManifest,
                'fallbackPackageMetadata' => $fallbackPackageMetadata,
                'filePlans' => $filePlans,
                'appliedFiles' => $appliedFiles,
                'sourceRepositoryId' => $sourceRepositoryId,
                'sourceRepositoryName' => $sourceRepositoryName,
                'sourceRegistryUrl' => $sourceRegistryUrl,
                'sourceArchiveUrl' => $sourceArchiveUrl,
                'archiveChecksum' => is_string($archiveChecksum) ? $archiveChecksum : null,
                'tempRoot' => $tempRoot,
            ];
        } catch (\Throwable $exception) {
            $this->rollbackAppliedFiles($appliedFiles);
            $this->ownershipService->repairStandardPaths($resolvedExtensionId);
            File::deleteDirectory($tempRoot);

            if ($exception instanceof DisplayException) {
                throw $exception;
            }

            throw new DisplayException('Failed to prepare the extension package for installation.', $exception);
        }
    }

    /**
     * @param array<string, mixed> $normalizedManifest
     * @param array<string, mixed> $fallbackPackageMetadata
     * @param array<int, array<string, mixed>> $filePlans
     */
    private function persistInstalledPackage(
        string $extensionId,
        array $normalizedManifest,
        array $fallbackPackageMetadata,
        array $filePlans,
        ?int $sourceRepositoryId,
        ?string $sourceRepositoryName,
        ?string $sourceRegistryUrl,
        ?string $sourceArchiveUrl,
        ?string $archiveChecksum
    ): ExtensionPackage {
        $packageModel = ExtensionPackage::query()->create([
            'extension_id' => $extensionId,
            'package_id' => Arr::get($normalizedManifest, 'package.id', Arr::get($fallbackPackageMetadata, 'id', $extensionId)),
            'name' => Arr::get($normalizedManifest, 'extension.name', Arr::get($fallbackPackageMetadata, 'name', $extensionId)),
            'description' => Arr::get($normalizedManifest, 'extension.description', Arr::get($fallbackPackageMetadata, 'description', '')),
            'author' => Arr::get($normalizedManifest, 'extension.author', Arr::get($fallbackPackageMetadata, 'author', 'M12Labs')),
            'icon' => Arr::get($normalizedManifest, 'extension.icon', Arr::get($fallbackPackageMetadata, 'icon', 'puzzle')),
            'route' => Arr::get($normalizedManifest, 'extension.route', Arr::get($fallbackPackageMetadata, 'route', $extensionId)),
            'installed_version' => Arr::get($normalizedManifest, 'package.version'),
            'source_repository_id' => $sourceRepositoryId,
            'source_repository_name' => $sourceRepositoryName,
            'source_registry_url' => $sourceRegistryUrl,
            'source_archive_url' => $sourceArchiveUrl,
            'package_checksum' => $archiveChecksum,
            'manifest' => $normalizedManifest,
            'installed_at' => now(),
        ]);

        foreach ($filePlans as $plan) {
            ExtensionPackageFile::query()->create([
                'extension_package_id' => $packageModel->id,
                'path' => $plan['path'],
                'operation' => $plan['operation'],
                'installed_checksum' => $plan['checksum'],
                'backup_path' => $plan['backupPath'],
                'backup_checksum' => $plan['backupChecksum'],
            ]);
        }

        ExtensionConfig::query()->firstOrCreate(
            ['extension_id' => $extensionId],
            [
                'enabled' => (bool) Arr::get($normalizedManifest, 'extension.defaults.enabled', false),
                'allowed_nests' => Arr::get($normalizedManifest, 'extension.defaults.allowedNests', []),
                'allowed_eggs' => Arr::get($normalizedManifest, 'extension.defaults.allowedEggs', []),
                'settings' => Arr::get($normalizedManifest, 'extension.defaults.settings', []),
            ]
        );

        return $packageModel;
    }

    private function assertExtensionNotInstalled(string $extensionId): void
    {
        if (ExtensionPackage::query()->where('extension_id', $extensionId)->exists()) {
            throw new DisplayException('This extension is already installed. Uninstall it before installing it again.');
        }
    }

    private function assertSupportedArchiveArtifact(string $archivePath): void
    {
        if (!Str::endsWith(Str::lower($archivePath), ['.m12labsextension', '.zip'])) {
            throw new DisplayException('Manual installs expect a .M12LabsExtension package file. Legacy .zip artifacts are still supported for compatibility.');
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<int, array<string, mixed>>
     */
    private function prepareFilePlans(string $extractPath, array $manifest, string $backupRoot, string $extensionId): array
    {
        $plans = [];
        $files = Arr::get($manifest, 'files', []);
        if (!is_array($files) || $files === []) {
            throw new DisplayException('The extension package manifest does not declare any installable files.');
        }

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $path = $this->artifactService->normalizeTargetPath((string) ($file['path'] ?? ''), $extensionId);
            $checksum = trim((string) ($file['sha256'] ?? ''));

            if ($path === '' || $checksum === '') {
                throw new DisplayException('The extension package manifest contains an invalid file entry.');
            }

            $sourcePath = $extractPath . '/' . $path;
            if (!is_file($sourcePath)) {
                throw new DisplayException(sprintf('The extension package is missing "%s".', $path));
            }

            $this->artifactService->verifyChecksum($sourcePath, $checksum, sprintf('file "%s"', $path));

            if (ExtensionPackageFile::query()->where('path', $path)->exists()) {
                throw new DisplayException(sprintf('The path "%s" is already managed by another installed extension.', $path));
            }

            $targetPath = base_path($path);
            $backupPath = null;
            $backupChecksum = null;
            $operation = is_file($targetPath) ? 'updated' : 'created';

            if ($operation === 'updated') {
                $backupPath = $backupRoot . '/' . $path;
                File::ensureDirectoryExists(dirname($backupPath));
                File::copy($targetPath, $backupPath);
                $backupChecksum = hash_file('sha256', $backupPath);
            }

            $plans[] = [
                'path' => $path,
                'sourcePath' => $sourcePath,
                'targetPath' => $targetPath,
                'operation' => $operation,
                'checksum' => $checksum,
                'backupPath' => $backupPath,
                'backupChecksum' => $backupChecksum,
            ];
        }

        return $plans;
    }

    /**
     * @param array<int, array<string, mixed>> $filePlans
     */
    private function assertWritableInstallTargets(array $filePlans): void
    {
        foreach ($filePlans as $plan) {
            $this->ownershipService->ensureWritablePath($plan['targetPath'], $plan['path']);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $appliedFiles
     */
    private function rollbackAppliedFiles(array $appliedFiles): void
    {
        foreach (array_reverse($appliedFiles) as $plan) {
            if (!empty($plan['backupPath']) && is_file($plan['backupPath'])) {
                File::ensureDirectoryExists(dirname($plan['targetPath']));
                File::copy($plan['backupPath'], $plan['targetPath']);

                continue;
            }

            if (is_file($plan['targetPath'])) {
                File::delete($plan['targetPath']);
            }
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

    /**
     * Run the security scanner on a downloaded archive.
     * Throws DisplayException if the scan is BLOCKED.
     * Logs a warning if WARNED but continues.
     * Scanner errors are logged but do not block installation.
     */
    private function runArchiveScan(string $archivePath, string $extensionId, string $action): void
    {
        try {
            $scanResult = $this->scanner->scan($archivePath);

            if ($scanResult->isBlocked()) {
                $summary = $scanResult->toArray()['summary'];
                throw new DisplayException(sprintf(
                    'Security scan BLOCKED %s of "%s": %d high-severity finding(s) detected. Review the scan report at: %s',
                    $action,
                    $extensionId,
                    $summary['high'],
                    $scanResult->reportPath
                ));
            }

            if ($scanResult->hasSevereFindings()) {
                Log::warning('Extension security scan found warnings.', [
                    'action'    => $action,
                    'extension' => $extensionId,
                    'warnings'  => $scanResult->toArray()['summary']['warnings'],
                    'report'    => $scanResult->reportPath,
                ]);
            }
        } catch (DisplayException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Scanner errors (missing binaries, corrupt archives, etc.) are logged
            // but do not block installation so a missing tool never prevents installs.
            Log::error('Extension security scanner encountered an error.', [
                'action'    => $action,
                'extension' => $extensionId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
