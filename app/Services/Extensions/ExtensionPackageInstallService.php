<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionPackageFile;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Exceptions\Service\Extension\ExtensionLockLostException;
use Everest\Exceptions\Service\Extension\CapabilityApprovalRequiredException;

class ExtensionPackageInstallService
{
    public function __construct(
        private ExtensionCatalogService $catalogService,
        private ExtensionPanelRebuildService $rebuildService,
        private ExtensionOperationLockService $operationLockService,
        private ExtensionFilesystemOwnershipService $ownershipService,
        private ExtensionInstallProgressService $progressService,
        private ExtensionPackageArtifactService $artifactService,
        private ExtensionMigrationService $migrationService,
        private ExtensionPermissionRegistry $permissionRegistry,
        private ExtensionPageManifestService $pageManifestService,
        private ExtensionSignatureService $signatureService,
        private ExtensionPackageSourceScanner $sourceScanner,
        private ExtensionRequirementService $requirementService,
    ) {
    }

    public function install(string $extensionId, int $repositoryId, ?string $version = null, ?string $approvedCapabilityHash = null): ExtensionPackage
    {
        return $this->operationLockService->withinLock('install', $extensionId, function () use ($extensionId, $repositoryId, $version, $approvedCapabilityHash) {
            $prepared = null;
            $packageModel = null;
            $committed = false;
            try {
                $prepared = $this->prepareInstall($extensionId, $repositoryId, $version, $approvedCapabilityHash);

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
                $committed = true;
                $this->progressService->report('install', $prepared['extensionId'], 'completed');

                return $packageModel;
            } catch (\Throwable $exception) {
                if ($exception instanceof ExtensionLockLostException) {
                    throw $exception;
                }

                if ($committed) {
                    $this->reportPostCommitFailure($exception);

                    return $packageModel;
                }

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

    public function installFromArchive(string $archivePath, ?string $sourceLabel = null, ?string $approvedCapabilityHash = null, bool $acknowledgeUnsigned = false): ExtensionPackage
    {
        $resolvedArchivePath = $this->artifactService->resolveArchivePath($archivePath);
        $this->assertSupportedArchiveArtifact($resolvedArchivePath);

        return $this->operationLockService->withinLock('install', basename($resolvedArchivePath), function () use ($resolvedArchivePath, $sourceLabel, $approvedCapabilityHash, $acknowledgeUnsigned) {
            $prepared = null;
            $packageModel = null;
            $committed = false;
            try {
                $prepared = $this->performInstallFileOps(
                    approvedCapabilityHash: $approvedCapabilityHash,
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
                    acknowledgeUnsigned: $acknowledgeUnsigned,
                    allowLocalArchive: true,
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
                $committed = true;
                $this->progressService->report('install', $prepared['extensionId'], 'completed');

                return $packageModel;
            } catch (\Throwable $exception) {
                if ($exception instanceof ExtensionLockLostException) {
                    throw $exception;
                }

                if ($committed) {
                    $this->reportPostCommitFailure($exception);

                    return $packageModel;
                }

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
     * @return array<string, mixed> opaque prepared state; pass to finalizeInstall() and rollbackInstall()
     */
    public function prepareInstall(string $extensionId, int $repositoryId, ?string $version = null, ?string $approvedCapabilityHash = null): array
    {
        $package = $this->catalogService->findRepositoryPackage($extensionId, $repositoryId, $version);
        $release = $package['latestRelease'];

        return $this->performInstallFileOps(
            approvedCapabilityHash: $approvedCapabilityHash,
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
        $this->operationLockService->checkpoint();

        return DB::transaction(function () use ($prepared) {
            return $this->persistInstalledPackage(
                extensionId: $prepared['extensionId'],
                parsedManifest: $prepared['parsedManifest'],
                fallbackPackageMetadata: $prepared['fallbackPackageMetadata'],
                filePlans: $prepared['filePlans'],
                sourceRepositoryId: $prepared['sourceRepositoryId'],
                sourceRepositoryName: $prepared['sourceRepositoryName'],
                sourceRegistryUrl: $prepared['sourceRegistryUrl'],
                sourceArchiveUrl: $prepared['sourceArchiveUrl'],
                archiveChecksum: $prepared['archiveChecksum'],
                signature: $prepared['signature'] ?? ['state' => 'unsigned', 'keyId' => null, 'verifiedAt' => null],
                signedManifest: $prepared['signedManifest'],
                manifestHash: $prepared['manifestHash'],
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
        $this->operationLockService->checkpoint();

        // Migrations roll back first: the migration files must still exist on
        // disk for the migrator to resolve their down() methods.
        if (!empty($prepared['appliedMigrations'])) {
            try {
                $this->migrationService->rollbackLastBatch($prepared['extensionId']);
            } catch (\Throwable $exception) {
                report($exception);
                $this->migrationService->writeMigrationLog(
                    $prepared['extensionId'],
                    'install-rollback',
                    ['migrations' => $prepared['appliedMigrations']],
                    $exception
                );
            }
        }

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
     *
     * @return array<string, mixed>
     */
    private function performInstallFileOps(
        ?string $approvedCapabilityHash,
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
        bool $acknowledgeUnsigned = false,
        bool $allowLocalArchive = false,
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
            if ($sourceRepositoryId !== null) {
                // The repository may have been disabled after catalog
                // resolution or capability approval. Recheck at the last safe
                // point before making its archive request.
                $this->catalogService->assertRepositoryEnabled($sourceRepositoryId);
            }
            $this->artifactService->downloadArchive($archiveLocation, $archivePath, $allowLocalArchive);

            $this->progressService->report('install', $resolvedExtensionId ?? 'unknown', 'extracting');
            $archiveChecksum = hash_file('sha256', $archivePath);
            if ($expectedArchiveChecksum !== null) {
                $this->artifactService->verifyChecksum($archivePath, $expectedArchiveChecksum, 'archive');
            }
            $this->artifactService->extractArchive($archivePath, $extractPath);

            $this->progressService->report('install', $resolvedExtensionId ?? 'unknown', 'validating');
            $manifestDocument = $this->artifactService->readPackageManifestDocument($extractPath);
            $manifest = $manifestDocument['manifest'];
            $parsedManifest = $this->artifactService->parseManifest($manifest, $expectedExtensionId, $expectedVersion);
            $extensionId = $parsedManifest->id;
            $resolvedExtensionId = $extensionId;
            $backupRoot = storage_path('app/extensions/backups/' . $extensionId . '/' . Str::uuid()->toString());

            $this->assertExtensionNotInstalled($extensionId);
            $this->requirementService->assertSatisfied($parsedManifest);

            // Before capability approval, and before a single file is copied:
            // an artifact the panel cannot attribute to anybody should never
            // reach the point where an operator is asked to consent to its
            // privileges.
            $signature = $this->signatureService->verify(
                $parsedManifest,
                $manifest,
                (string) $archiveChecksum,
                // The unsigned acknowledgement is only ever honoured for a
                // local archive an operator handed over deliberately, never
                // for anything fetched from a repository.
                $acknowledgeUnsigned && $sourceRepositoryId === null,
                auth()->user()?->email,
                $manifestDocument['canonical'],
            );

            // Defense in depth for unsigned packages admitted through the
            // explicitly acknowledged local-file path.
            if ($signature['state'] !== 'verified' && $this->signatureService->signingRequired()) {
                $this->signatureService->assertCapabilitiesAllowedUnverified($parsedManifest);
            }

            $this->assertCapabilitiesApproved($extensionId, $parsedManifest, $approvedCapabilityHash);
            // Compatibility is enforced only for repository fetches. A manual
            // package upload (sourceRepositoryId === null) is an explicit operator
            // action and is trusted to run whatever it ships, so we never block it
            // on the declared panel-version range.
            if ($sourceRepositoryId !== null) {
                $this->artifactService->assertCompatiblePanelVersions($compatiblePanelVersions);
                $this->artifactService->assertCompatiblePanelVersions($parsedManifest->compatiblePanelVersions);
            }
            $this->operationLockService->checkpoint();
            $this->ownershipService->repairStandardPaths($extensionId);

            $filePlans = $this->prepareFilePlans($extractPath, $parsedManifest, $backupRoot, $extensionId);
            $this->sourceScanner->assertSafe(
                $extensionId,
                $filePlans,
                (array) ($parsedManifest->requirements['npmPackages'] ?? []),
            );
            $this->assertWritableInstallTargets($filePlans);
            $generatedPath = $this->pageManifestService->relativePath($extensionId);
            $this->ownershipService->ensureWritablePath(base_path($generatedPath), $generatedPath);

            $this->progressService->report('install', $extensionId, 'copying');
            foreach ($filePlans as $plan) {
                $this->operationLockService->checkpoint();
                File::ensureDirectoryExists(dirname($plan['targetPath']));
                File::copy($plan['sourcePath'], $plan['targetPath']);
                $appliedFiles[] = $plan;
            }

            // Written after the package's own files and before the rebuild
            // that follows, because the bundle reads it. Tracked as a file plan
            // so it is checksummed, rolled back and uninstalled like any other.
            $this->operationLockService->checkpoint();
            $generated = $this->pageManifestService->write($parsedManifest, $backupRoot);
            $filePlans[] = $generated;
            $appliedFiles[] = $generated;

            $appliedMigrations = $this->runPackageMigrations($extensionId, $filePlans, 'install');

            return [
                'signature' => $signature,
                'extensionId' => $extensionId,
                'appliedMigrations' => $appliedMigrations,
                'parsedManifest' => $parsedManifest,
                'signedManifest' => $manifestDocument['json'],
                'manifestHash' => hash('sha256', $manifestDocument['canonical']),
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
            if ($exception instanceof ExtensionLockLostException) {
                File::deleteDirectory($tempRoot);

                throw $exception;
            }

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
     * @param array<string, mixed> $fallbackPackageMetadata
     * @param array<int, array<string, mixed>> $filePlans
     */
    private function persistInstalledPackage(
        string $extensionId,
        ExtensionManifest $parsedManifest,
        array $fallbackPackageMetadata,
        array $filePlans,
        ?int $sourceRepositoryId,
        ?string $sourceRepositoryName,
        ?string $sourceRegistryUrl,
        ?string $sourceArchiveUrl,
        ?string $archiveChecksum,
        array $signature,
        string $signedManifest,
        string $manifestHash,
    ): ExtensionPackage {
        $packageModel = ExtensionPackage::query()->create([
            'extension_id' => $extensionId,
            'package_id' => $parsedManifest->packageId,
            'name' => $parsedManifest->name,
            'description' => $parsedManifest->description,
            'author' => $parsedManifest->publisher ?? Arr::get($fallbackPackageMetadata, 'author', 'M12Labs'),
            'icon' => $parsedManifest->icon,
            // v3 packages declare pages rather than a single route; the column
            // is retained for the catalog's legacy shape and mirrors the id.
            'route' => $extensionId,
            'installed_version' => $parsedManifest->version,
            'source_repository_id' => $sourceRepositoryId,
            'source_repository_name' => $sourceRepositoryName,
            'source_registry_url' => $sourceRegistryUrl,
            'source_archive_url' => $sourceArchiveUrl,
            'package_checksum' => $archiveChecksum,
            'manifest' => $parsedManifest->jsonSerialize(),
            'manifest_version' => ExtensionManifest::VERSION,
            'capabilities' => $parsedManifest->capabilities->jsonSerialize(),
            'capability_hash' => $parsedManifest->capabilities->hash(),
            // What the operator actually approved, recorded rather than merely
            // checked. Install asserted it a moment ago and then dropped it,
            // which left every fresh install with no approval on record — so the
            // package's first update re-asked for privileges it had already been
            // granted, and the panel could not answer what was consented to.
            'approved_capability_hash' => $parsedManifest->capabilities->hash(),
            'manifest_hash' => $manifestHash,
            'signed_manifest' => $signedManifest,
            'publisher' => $parsedManifest->publisher,
            'signature_state' => $signature['state'],
            'signature_key_id' => $signature['keyId'],
            'signature_verified_at' => $signature['verifiedAt'],
            'state' => 'installed_disabled',
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

        // Reaching here means the capability diff was approved, so the admin
        // permissions the package declares become assignable. They are not
        // granted to anybody — no role is touched.
        $this->permissionRegistry->sync(
            $extensionId,
            $parsedManifest->capabilities,
            approved: true,
            approvedBy: auth()->id(),
        );

        ExtensionConfig::query()->firstOrCreate(
            ['extension_id' => $extensionId],
            [
                // A freshly installed package is never activated implicitly;
                // enabling is a separate, explicit administrator action.
                'enabled' => false,
                'allowed_nests' => $parsedManifest->defaultAllowedNests(),
                'allowed_eggs' => $parsedManifest->defaultAllowedEggs(),
                'settings' => $parsedManifest->defaultSettings(),
            ]
        );

        return $packageModel;
    }

    /**
     * Run any migrations the package shipped, after its files have been
     * copied into place. A failure mid-run rolls the partial batch back,
     * writes a migration error log, and aborts the operation.
     *
     * @param array<int, array<string, mixed>> $filePlans
     *
     * @return array<int, string> the migration files applied (empty when the package ships none)
     */
    private function runPackageMigrations(string $extensionId, array $filePlans, string $action): array
    {
        $migrationsPrefix = $this->migrationService->migrationPath($extensionId) . '/';
        $migrationFiles = [];
        foreach ($filePlans as $plan) {
            if (Str::startsWith($plan['path'], $migrationsPrefix)) {
                $migrationFiles[] = $plan['targetPath'];
            }
        }

        if ($migrationFiles === []) {
            return [];
        }

        $this->progressService->report($action, $extensionId, 'migrating');
        $this->migrationService->assertMigrationConventions($extensionId, $migrationFiles);

        try {
            $this->operationLockService->checkpoint();
            $result = $this->migrationService->run($extensionId);
            $this->operationLockService->checkpoint();
        } catch (\Throwable $exception) {
            if ($exception instanceof ExtensionLockLostException) {
                throw $exception;
            }

            try {
                $this->migrationService->rollbackLastBatch($extensionId);
            } catch (\Throwable $rollbackException) {
                report($rollbackException);
            }

            $logPath = $this->migrationService->writeMigrationLog(
                $extensionId,
                $action,
                ['migrations' => array_map('basename', $migrationFiles)],
                $exception
            );

            throw new DisplayException(sprintf('A migration shipped by "%s" failed and was rolled back. Details were written to %s.', $extensionId, $logPath), $exception);
        }

        return array_map('basename', $result['files']);
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
     * An install grants every privilege the package declares, so it always
     * requires explicit consent.
     *
     * The diff is computed from the verified manifest rather than from registry
     * metadata — which is why the archive is downloaded and checked first, and
     * why this runs before a single file is copied.
     */
    private function assertCapabilitiesApproved(string $extensionId, ExtensionManifest $manifest, ?string $approvedCapabilityHash): void
    {
        $diff = ExtensionCapabilityDiff::between(null, $manifest->capabilities);

        if (!$diff->isEscalation()) {
            return;
        }

        if ($approvedCapabilityHash !== null && hash_equals($diff->hash, $approvedCapabilityHash)) {
            return;
        }

        throw new CapabilityApprovalRequiredException($extensionId, $diff);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prepareFilePlans(string $extractPath, ExtensionManifest $manifest, string $backupRoot, string $extensionId): array
    {
        $plans = [];

        foreach ($manifest->files as $file) {
            // The parser has already validated the shape; this re-checks that
            // the path stays inside the two install roots for this extension.
            $path = $this->artifactService->normalizeTargetPath($file['path'], $extensionId);
            $checksum = $file['sha256'];

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
            $this->operationLockService->checkpoint();

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

    private function reportPostCommitFailure(\Throwable $exception): void
    {
        try {
            report($exception);
        } catch (\Throwable) {
            // Never compensate files after the database has committed merely
            // because progress reporting or another cleanup step failed.
        }
    }
}
