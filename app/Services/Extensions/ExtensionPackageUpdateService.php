<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Everest\Models\ExtensionConfig;
use Illuminate\Support\Facades\Log;
use Everest\Models\ExtensionPackage;
use Illuminate\Support\Facades\File;
use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionPackageFile;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;
use Everest\Exceptions\Service\Extension\CapabilityApprovalRequiredException;

class ExtensionPackageUpdateService
{
    public function __construct(
        private ExtensionCatalogService $catalogService,
        private ExtensionPanelRebuildService $rebuildService,
        private ExtensionOperationLockService $operationLockService,
        private ExtensionFilesystemOwnershipService $ownershipService,
        private ExtensionInstallProgressService $progressService,
        private ExtensionPackageArtifactService $artifactService,
        private ExtensionPackageFileService $fileService,
        private ExtensionMigrationService $migrationService,
        private ExtensionPackageIntegrityService $integrityService,
        private ExtensionPermissionRegistry $permissionRegistry,
        private ExtensionJobDrainService $drainService,
        private ExtensionPageManifestService $pageManifestService,
        private ExtensionSignatureService $signatureService,
        private ExtensionFrontendImportScanner $importScanner,
        private ExtensionRequirementService $requirementService,
    ) {
    }

    /**
     * Update an extension from a configured repository.
     */
    public function update(string $extensionId, int $repositoryId, ?string $version = null, ?string $approvedCapabilityHash = null, bool $acknowledgeModified = false): ExtensionPackage
    {
        return $this->operationLockService->withinLock('update', $extensionId, function () use ($extensionId, $repositoryId, $version, $approvedCapabilityHash, $acknowledgeModified) {
            $prepared = null;
            $packageModel = null;
            $committed = false;
            try {
                $prepared = $this->prepareUpdate($extensionId, $repositoryId, $version, $approvedCapabilityHash, $acknowledgeModified);

                $this->rebuildService->rebuild(
                    sprintf('Update extension %s', $prepared['extensionId']),
                    function (int $index) use ($prepared): void {
                        $this->progressService->report(
                            'update',
                            $prepared['extensionId'],
                            $index === 0 ? 'optimizing' : 'building'
                        );
                    }
                );

                $this->progressService->report('update', $prepared['extensionId'], 'registering');
                $packageModel = $this->finalizeUpdate($prepared);
                $committed = true;
                $this->completeUpdate($prepared);
                $this->progressService->report('update', $prepared['extensionId'], 'completed');

                return $packageModel;
            } catch (\Throwable $exception) {
                if ($committed && $packageModel instanceof ExtensionPackage) {
                    $this->reportPostCommitFailure($exception);

                    return $packageModel;
                }

                if ($prepared !== null) {
                    $this->rollbackUpdate($prepared);
                    $this->attemptRollbackRebuild($prepared['extensionId'], 'update rollback');
                }

                if ($exception instanceof DisplayException) {
                    throw $exception;
                }

                throw new DisplayException('Failed to update the selected extension package.', $exception);
            } finally {
                $this->progressService->clear();
                if ($prepared !== null) {
                    $this->ownershipService->repairStandardPaths($prepared['extensionId']);
                    $this->cleanupPreparedUpdate($prepared);
                }
            }
        });
    }

    /**
     * Update an extension from a local .M12LabsExtension archive.
     */
    public function updateFromArchive(string $archivePath, ?string $sourceLabel = null, ?string $approvedCapabilityHash = null, bool $acknowledgeModified = false, bool $acknowledgeUnsigned = false): ExtensionPackage
    {
        $resolvedPath = $this->artifactService->resolveArchivePath($archivePath);
        $this->assertSupportedArchiveArtifact($resolvedPath);

        return $this->operationLockService->withinLock('update', basename($resolvedPath), function () use ($resolvedPath, $sourceLabel, $approvedCapabilityHash, $acknowledgeModified, $acknowledgeUnsigned) {
            $prepared = null;
            $packageModel = null;
            $committed = false;
            try {
                $prepared = $this->performUpdateFileOps(
                    approvedCapabilityHash: $approvedCapabilityHash,
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
                    acknowledgeModified: $acknowledgeModified,
                    acknowledgeUnsigned: $acknowledgeUnsigned,
                    allowLocalArchive: true,
                );

                $this->rebuildService->rebuild(
                    sprintf('Update extension %s', $prepared['extensionId']),
                    function (int $index) use ($prepared): void {
                        $this->progressService->report(
                            'update',
                            $prepared['extensionId'],
                            $index === 0 ? 'optimizing' : 'building'
                        );
                    }
                );

                $this->progressService->report('update', $prepared['extensionId'], 'registering');
                $packageModel = $this->finalizeUpdate($prepared);
                $committed = true;
                $this->completeUpdate($prepared);
                $this->progressService->report('update', $prepared['extensionId'], 'completed');

                return $packageModel;
            } catch (\Throwable $exception) {
                if ($committed && $packageModel instanceof ExtensionPackage) {
                    $this->reportPostCommitFailure($exception);

                    return $packageModel;
                }

                if ($prepared !== null) {
                    $this->rollbackUpdate($prepared);
                    $this->attemptRollbackRebuild($prepared['extensionId'], 'update rollback');
                }

                if ($exception instanceof DisplayException) {
                    throw $exception;
                }

                throw new DisplayException('Failed to update the selected extension package.', $exception);
            } finally {
                $this->progressService->clear();
                if ($prepared !== null) {
                    $this->ownershipService->repairStandardPaths($prepared['extensionId']);
                    $this->cleanupPreparedUpdate($prepared);
                }
            }
        });
    }

    /**
     * Prepare an extension update: download, extract, validate, and swap files into place.
     * Does NOT rebuild the panel or modify the database.
     *
     * Used by the batch service to prepare multiple extensions before a single rebuild.
     * After calling this for each extension, call ExtensionPanelRebuildService::rebuild()
     * once, then finalizeUpdate() for each prepared result.
     *
     * @return array<string, mixed> opaque prepared state; pass to finalizeUpdate() and rollbackUpdate()
     */
    public function prepareUpdate(string $extensionId, int $repositoryId, ?string $version = null, ?string $approvedCapabilityHash = null, bool $acknowledgeModified = false): array
    {
        $package = $this->catalogService->findRepositoryPackage($extensionId, $repositoryId, $version);
        $release = $package['latestRelease'];

        return $this->performUpdateFileOps(
            approvedCapabilityHash: $approvedCapabilityHash,
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
            acknowledgeModified: $acknowledgeModified,
        );
    }

    /**
     * Finalize an update prepared via prepareUpdate(): write updated package records to the database.
     * Must be called after the panel has been rebuilt.
     *
     * @param array<string, mixed> $prepared
     */
    public function finalizeUpdate(array $prepared): ExtensionPackage
    {
        $existingPackage = $prepared['existingPackage'];
        $parsedManifest = $prepared['parsedManifest'];
        $fallbackPackageMetadata = $prepared['fallbackPackageMetadata'];
        $newFilePlans = $prepared['newFilePlans'];
        $resolvedExtensionId = $prepared['extensionId'];
        $archiveChecksum = $prepared['archiveChecksum'];
        $sourceRepositoryId = $prepared['sourceRepositoryId'];
        $sourceRepositoryName = $prepared['sourceRepositoryName'];
        $sourceRegistryUrl = $prepared['sourceRegistryUrl'];
        $sourceArchiveUrl = $prepared['sourceArchiveUrl'];
        $signature = $prepared['signature'] ?? ['state' => 'unsigned', 'keyId' => null, 'verifiedAt' => null];
        $signedManifest = $prepared['signedManifest'];
        $manifestHash = $prepared['manifestHash'];
        $approvedCapabilityHash = $prepared['approvedCapabilityHash'] ?? null;

        return DB::transaction(function () use (
            $archiveChecksum,
            $existingPackage,
            $fallbackPackageMetadata,
            $newFilePlans,
            $parsedManifest,
            $resolvedExtensionId,
            $sourceArchiveUrl,
            $sourceRegistryUrl,
            $sourceRepositoryId,
            $sourceRepositoryName,
            $signature,
            $signedManifest,
            $manifestHash,
            $approvedCapabilityHash
        ): ExtensionPackage {
            ExtensionPackageFile::query()
                ->where('extension_package_id', $existingPackage->id)
                ->delete();

            $existingPackage->update([
                'package_id'             => $parsedManifest->packageId,
                'name'                   => $parsedManifest->name,
                'description'            => $parsedManifest->description,
                'author'                 => $parsedManifest->publisher ?? Arr::get($fallbackPackageMetadata, 'author', 'M12Labs'),
                'icon'                   => $parsedManifest->icon,
                'route'                  => $resolvedExtensionId,
                'previous_version'       => $existingPackage->installed_version,
                'installed_version'      => $parsedManifest->version,
                'source_repository_id'   => $sourceRepositoryId ?? $existingPackage->source_repository_id,
                'source_repository_name' => $sourceRepositoryName ?? $existingPackage->source_repository_name,
                'source_registry_url'    => $sourceRegistryUrl ?? $existingPackage->source_registry_url,
                'source_archive_url'     => $sourceArchiveUrl,
                'package_checksum'       => is_string($archiveChecksum) ? $archiveChecksum : null,
                'manifest'               => $parsedManifest->jsonSerialize(),
                'manifest_version'       => ExtensionManifest::VERSION,
                'capabilities'           => $parsedManifest->capabilities->jsonSerialize(),
                'capability_hash'        => $parsedManifest->capabilities->hash(),
                // The set an administrator consented to. Recording it is what
                // stops the next update re-prompting for privileges that were
                // already granted.
                'approved_capability_hash' => $approvedCapabilityHash,
                'manifest_hash'          => $manifestHash,
                'signed_manifest'        => $signedManifest,
                'publisher'              => $parsedManifest->publisher,
                'signature_state'        => $signature['state'],
                'signature_key_id'       => $signature['keyId'],
                'signature_verified_at'  => $signature['verifiedAt'],
                // Lifecycle state is re-derived rather than carried over. A
                // package quarantined as 'unsupported' for its manifest version
                // has just been replaced by one this panel accepts, and leaving
                // the old state (and its now-false reason) would keep it inert
                // with no way back — which made updating out of v2 pointless.
                // An already-runnable package keeps its enabled flag.
                'state'                  => $this->stateAfterUpdate($resolvedExtensionId),
                'state_reason'           => null,
                'installed_at'           => now(),
            ]);

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

            // Permissions the new version dropped are removed from every role
            // here rather than left dangling; ones it added were part of the
            // approved capability diff.
            $this->permissionRegistry->sync(
                $resolvedExtensionId,
                $parsedManifest->capabilities,
                approved: true,
                approvedBy: auth()->id(),
            );

            // Refresh before the transaction commits. If this read fails, the
            // transaction still rolls back and the caller may safely restore
            // the old files. Refreshing after commit created an uncertainty
            // window where a read error triggered compensation against an
            // already-committed package row.
            return $existingPackage->fresh(['repository', 'files']);
        });
    }

    /**
     * Remove backups made obsolete by a committed update.
     *
     * This deliberately runs after the database transaction (and, for a batch,
     * after the outer transaction containing every item). A failed finalizer
     * must leave the old backups intact so filesystem compensation can restore
     * the version still recorded in the database.
     *
     * @param array<string, mixed> $prepared
     */
    public function completeUpdate(array $prepared): void
    {
        foreach ($prepared['oldOnlyFiles'] ?? [] as $oldFile) {
            if ($oldFile->operation !== 'updated' || !$oldFile->backup_path || !is_file($oldFile->backup_path)) {
                continue;
            }

            try {
                File::delete($oldFile->backup_path);
            } catch (\Throwable $exception) {
                // The update is already committed. A stale backup is safe and
                // can be pruned later; turning cleanup into a rollback here
                // would make the database and installed files disagree.
                report($exception);
            }
        }
    }

    /**
     * Roll back a prepared update by restoring files from the rollback snapshot.
     *
     * @param array<string, mixed> $prepared
     */
    public function rollbackUpdate(array $prepared): void
    {
        // Only migrations applied by THIS update are reverted (they form the
        // newest batch); the previous version's migrations must survive. Runs
        // before the file snapshot restore so the new migration files are
        // still on disk for their down() methods.
        if (!empty($prepared['appliedMigrations'])) {
            try {
                $this->migrationService->rollbackLastBatch($prepared['extensionId']);
            } catch (\Throwable $exception) {
                report($exception);
                $this->migrationService->writeMigrationLog(
                    $prepared['extensionId'],
                    'update-rollback',
                    ['migrations' => $prepared['appliedMigrations']],
                    $exception
                );
            }
        }

        $this->rollbackNewFilePlans(
            $prepared['existingPackage'],
            $prepared['newFilePlans'] ?? []
        );

        if ($prepared['existingPackage']) {
            $this->fileService->restoreRollbackSnapshot($prepared['existingPackage']->files->all(), $prepared['rollbackRoot']);
        }

        if (!empty($prepared['newBackupRoot'])) {
            File::deleteDirectory($prepared['newBackupRoot']);
        }

        $this->ownershipService->repairStandardPaths($prepared['extensionId']);
    }

    /**
     * Clean up temp files associated with a prepared update.
     *
     * @param array<string, mixed> $prepared
     */
    public function cleanupPreparedUpdate(array $prepared): void
    {
        // Reached from update() and from the batch service alike, on success
        // and on rollback. The extension must stop refusing dispatches either
        // way — after a rollback it is still installed and expected to work.
        if (isset($prepared['extensionId'])) {
            $this->drainService->endDrain($prepared['extensionId']);
        }

        if (!empty($prepared['tempRoot'])) {
            File::deleteDirectory($prepared['tempRoot']);
        }

        if (!empty($prepared['rollbackRoot'])) {
            File::deleteDirectory($prepared['rollbackRoot']);
        }
    }

    /**
     * Perform the file-operations phase of an update (download → extract → validate → swap files).
     * Returns the prepared state needed to finalize or roll back.
     *
     * @param array<string, mixed> $fallbackPackageMetadata
     * @param array<int, string> $compatiblePanelVersions
     *
     * @return array<string, mixed>
     */
    private function performUpdateFileOps(
        ?string $approvedCapabilityHash,
        string $archiveLocation,
        ?string $extensionId,
        ?string $expectedVersion,
        ?string $expectedArchiveChecksum,
        array $compatiblePanelVersions,
        ?int $sourceRepositoryId,
        ?string $sourceRepositoryName,
        ?string $sourceRegistryUrl,
        string $sourceArchiveUrl,
        array $fallbackPackageMetadata,
        bool $acknowledgeModified = false,
        bool $acknowledgeUnsigned = false,
        bool $allowLocalArchive = false,
    ): array {
        $tempRoot = storage_path('app/extensions/tmp/' . Str::uuid()->toString());
        $archivePath = $tempRoot . '/' . ExtensionPackageArtifactService::PACKAGE_ARTIFACT_FILENAME;
        $extractPath = $tempRoot . '/extract';
        $rollbackRoot = storage_path('app/extensions/tmp-update/' . Str::uuid()->toString());
        $resolvedExtensionId = $extensionId;
        $existingPackage = null;
        $newFilePlans = [];
        $newBackupRoot = null;

        File::ensureDirectoryExists($tempRoot);
        File::ensureDirectoryExists($extractPath);
        File::ensureDirectoryExists($rollbackRoot);

        try {
            $this->progressService->report('update', $resolvedExtensionId ?? 'unknown', 'downloading');
            if ($sourceRepositoryId !== null) {
                // Capability approval and batch preparation can introduce a
                // delay after catalog resolution. A disabled source must not
                // get one final request merely because its model was stale.
                $this->catalogService->assertRepositoryEnabled($sourceRepositoryId);
            }
            $this->artifactService->downloadArchive($archiveLocation, $archivePath, $allowLocalArchive);

            $this->progressService->report('update', $resolvedExtensionId ?? 'unknown', 'extracting');
            $archiveChecksum = hash_file('sha256', $archivePath);
            if ($expectedArchiveChecksum !== null) {
                $this->artifactService->verifyChecksum($archivePath, $expectedArchiveChecksum, 'archive');
            }
            $this->artifactService->extractArchive($archivePath, $extractPath);

            $this->progressService->report('update', $resolvedExtensionId ?? 'unknown', 'validating');
            $manifestDocument = $this->artifactService->readPackageManifestDocument($extractPath);
            $manifest = $manifestDocument['manifest'];
            $parsedManifest = $this->artifactService->parseManifest($manifest, $extensionId, $expectedVersion);
            $resolvedExtensionId = $parsedManifest->id;
            $this->requirementService->assertSatisfied($parsedManifest);

            $existingPackage = ExtensionPackage::query()
                ->with('files')
                ->where('extension_id', $resolvedExtensionId)
                ->first();

            if (!$existingPackage) {
                throw new DisplayException('This extension is not currently installed. Use the install command to install it first.');
            }

            $this->artifactService->assertCompatiblePanelVersions($compatiblePanelVersions);
            $this->artifactService->assertCompatiblePanelVersions($parsedManifest->compatiblePanelVersions);

            // Verify who signed the NEW release, exactly as an install does.
            // Without this an update inherited whatever signature state the
            // previous version happened to carry: a package updated to a signed
            // release still read as unsigned, and — worse — an unsigned or
            // untrusted release could be installed over a verified one without
            // the panel ever checking.
            $signature = $this->signatureService->verify(
                $parsedManifest,
                $manifest,
                (string) $archiveChecksum,
                $acknowledgeUnsigned && $sourceRepositoryId === null,
                auth()->user()?->email,
                $manifestDocument['canonical'],
            );

            if ($signature['state'] !== 'verified' && $this->signatureService->signingRequired()) {
                $this->signatureService->assertCapabilitiesAllowedUnverified($parsedManifest);
            }

            $this->assertCapabilitiesApproved($existingPackage, $parsedManifest, $approvedCapabilityHash);
            $this->ownershipService->repairStandardPaths($resolvedExtensionId);

            // An update replaces the very class files a queued job names, so
            // the queue is emptied first for the same reason an uninstall does
            // it: a payload deserialized against the new code is a job running
            // with arguments the old version built.
            $this->progressService->report('update', $resolvedExtensionId, 'draining');
            $this->drainService->beginDrain($resolvedExtensionId);
            $this->drainService->cancelQueued($resolvedExtensionId);
            $this->drainService->waitForDrain($resolvedExtensionId, (int) config('extensions.queues.drain_timeout_seconds', 60));
            $this->drainService->assertSafeToRemove($resolvedExtensionId);

            $discarded = $this->fileService->assertFilesUnmodified($existingPackage->files->all(), 'updated', $acknowledgeModified, (string) $resolvedExtensionId);
            if ($discarded !== []) {
                Log::warning('Updating an extension whose files were modified after installation.', [
                    'extension' => $resolvedExtensionId,
                    'modified' => $discarded,
                ]);
            }
            $this->fileService->createRollbackSnapshot($existingPackage->files->all(), $rollbackRoot);

            $newBackupRoot = storage_path('app/extensions/backups/' . $resolvedExtensionId . '/' . Str::uuid()->toString());

            $newFilePlans = $this->prepareUpdateFilePlans(
                $extractPath,
                $parsedManifest,
                $newBackupRoot,
                $resolvedExtensionId,
                $existingPackage
            );

            $generatedPath = $this->pageManifestService->relativePath($resolvedExtensionId);
            $newFilePaths = [...array_column($newFilePlans, 'path'), $generatedPath];
            /** @var array<int, ExtensionPackageFile> $oldOnlyFiles */
            $oldOnlyFiles = $existingPackage->files
                ->filter(fn (ExtensionPackageFile $f) => !in_array($f->path, $newFilePaths, true))
                ->values()
                ->all();

            $this->assertWritableUpdateTargets($newFilePlans, $oldOnlyFiles);
            $this->ownershipService->ensureWritablePath(base_path($generatedPath), $generatedPath);

            $this->progressService->report('update', $resolvedExtensionId, 'removing');
            foreach ($oldOnlyFiles as $oldFile) {
                $targetPath = base_path($oldFile->path);

                if ($oldFile->operation === 'updated') {
                    if ($oldFile->backup_path && is_file($oldFile->backup_path)) {
                        File::ensureDirectoryExists(dirname($targetPath));
                        File::copy($oldFile->backup_path, $targetPath);
                    }
                } elseif (is_file($targetPath)) {
                    File::delete($targetPath);
                }
            }

            $this->progressService->report('update', $resolvedExtensionId, 'copying');
            foreach ($newFilePlans as $plan) {
                File::ensureDirectoryExists(dirname($plan['targetPath']));
                File::copy($plan['sourcePath'], $plan['targetPath']);
            }

            // Regenerated from the new manifest, so a version that changes a
            // page or named frontend slot takes effect on this rebuild rather
            // than at the next install.
            /** @var ExtensionPackageFile|null $previousGeneratedFile */
            $previousGeneratedFile = $existingPackage->files->firstWhere('path', $generatedPath);
            $newFilePlans[] = $this->pageManifestService->write(
                $parsedManifest,
                $newBackupRoot,
                $previousGeneratedFile,
            );

            $appliedMigrations = $this->runNewMigrations($resolvedExtensionId, $newFilePlans);

            return [
                'extensionId'             => $resolvedExtensionId,
                'appliedMigrations'       => $appliedMigrations,
                'existingPackage'         => $existingPackage,
                'parsedManifest'          => $parsedManifest,
                'signedManifest'          => $manifestDocument['json'],
                'manifestHash'            => hash('sha256', $manifestDocument['canonical']),
                'fallbackPackageMetadata' => $fallbackPackageMetadata,
                'newFilePlans'            => $newFilePlans,
                'oldOnlyFiles'            => $oldOnlyFiles,
                'archiveChecksum'         => is_string($archiveChecksum) ? $archiveChecksum : null,
                // Who signed THIS release, and the capability set the operator
                // consented to. Both are re-derived per update: carrying the
                // previous version's forward would let an unsigned release
                // inherit a verified badge, and would re-prompt for privileges
                // that were already granted.
                'signature'               => $signature,
                'approvedCapabilityHash'  => $parsedManifest->capabilities->hash(),
                'sourceRepositoryId'      => $sourceRepositoryId,
                'sourceRepositoryName'    => $sourceRepositoryName,
                'sourceRegistryUrl'       => $sourceRegistryUrl,
                'sourceArchiveUrl'        => $sourceArchiveUrl,
                'rollbackRoot'            => $rollbackRoot,
                'newBackupRoot'           => $newBackupRoot,
                'tempRoot'                => $tempRoot,
            ];
        } catch (\Throwable $exception) {
            if ($existingPackage) {
                $this->rollbackNewFilePlans($existingPackage, $newFilePlans);
                $this->fileService->restoreRollbackSnapshot($existingPackage->files->all(), $rollbackRoot);
            }

            // Nothing was prepared, so cleanupPreparedUpdate() will never run
            // for this attempt; the drain has to be lifted here or the still
            // installed extension keeps refusing jobs until the flag expires.
            if ($resolvedExtensionId !== null) {
                $this->drainService->endDrain($resolvedExtensionId);
            }

            $this->ownershipService->repairStandardPaths($resolvedExtensionId);
            File::deleteDirectory($tempRoot);
            File::deleteDirectory($rollbackRoot);
            if ($newBackupRoot !== null) {
                File::deleteDirectory($newBackupRoot);
            }

            if ($exception instanceof DisplayException) {
                throw $exception;
            }

            throw new DisplayException('Failed to prepare the extension package for update.', $exception);
        }
    }

    /**
     * Undo paths introduced by the new release before restoring the snapshot of
     * the old tracked set. Snapshot restoration alone cannot remove a newly
     * created path, nor restore an untracked panel file that this version
     * temporarily replaced.
     *
     * @param array<int, array<string, mixed>> $newFilePlans
     */
    private function rollbackNewFilePlans(ExtensionPackage $existingPackage, array $newFilePlans): void
    {
        $oldPaths = array_fill_keys($existingPackage->files->pluck('path')->all(), true);

        foreach (array_reverse($newFilePlans) as $plan) {
            $path = (string) ($plan['path'] ?? '');
            $targetPath = $plan['targetPath'] ?? null;

            if ($path === '' || !is_string($targetPath) || isset($oldPaths[$path])) {
                continue;
            }

            $backupPath = $plan['backupPath'] ?? null;
            if (($plan['operation'] ?? null) === 'updated' && is_string($backupPath) && is_file($backupPath)) {
                $this->ownershipService->ensureWritablePath($targetPath, $path);
                File::ensureDirectoryExists(dirname($targetPath));
                File::copy($backupPath, $targetPath);

                continue;
            }

            if (is_file($targetPath)) {
                $this->ownershipService->ensureRemovablePath($targetPath, $path);
                File::delete($targetPath);
            }
        }
    }

    /**
     * The lifecycle state a freshly updated package should hold.
     *
     * Derived from the operator's enable flag rather than from the previous
     * state, so a package rehabilitated out of 'unsupported' lands somewhere
     * runnable instead of keeping a quarantine that no longer applies.
     */
    private function stateAfterUpdate(string $extensionId): string
    {
        $enabled = ExtensionConfig::query()
            ->where('extension_id', $extensionId)
            ->value('enabled');

        return $enabled ? 'enabled' : 'installed_disabled';
    }

    /**
     * An update must never silently widen what a package can do.
     *
     * The diff is taken against the capabilities in the authenticated retained
     * manifest. A release that only narrows or keeps them proceeds untouched;
     * one that adds a privilege needs the administrator to consent to that
     * specific set. The adjacent stored projection and digest are not an
     * authority: a database writer can change both.
     */
    public function assertCapabilitiesApproved(ExtensionPackage $existingPackage, ExtensionManifest $manifest, ?string $approvedCapabilityHash): void
    {
        $inspection = $this->integrityService->inspect($existingPackage);
        $installed = $inspection->manifestAuthentic
            ? $inspection->manifest?->capabilities
            : null;

        $diff = ExtensionCapabilityDiff::between($installed, $manifest->capabilities);

        if (!$diff->isEscalation()) {
            return;
        }

        if ($approvedCapabilityHash !== null && hash_equals($diff->hash, $approvedCapabilityHash)) {
            return;
        }

        throw new CapabilityApprovalRequiredException($manifest->id, $diff);
    }

    /**
     * Build the file plans for the new version, inheriting pre-extension backups
     * from the current install for any paths that were already tracked.
     *
     * @return array<int, array<string, mixed>>
     */
    private function prepareUpdateFilePlans(
        string $extractPath,
        ExtensionManifest $manifest,
        string $newBackupRoot,
        string $extensionId,
        ExtensionPackage $existingPackage,
    ): array {
        $plans = [];

        /** @var array<string, ExtensionPackageFile> $oldFilesByPath */
        $oldFilesByPath = $existingPackage->files->keyBy('path')->all();

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
                'path'           => $path,
                'sourcePath'     => $sourcePath,
                'targetPath'     => $targetPath,
                'operation'      => $operation,
                'checksum'       => $checksum,
                'backupPath'     => $backupPath,
                'backupChecksum' => $backupChecksum,
            ];
        }

        // Checked on update as well as install: a package that shipped a clean
        // release once can reach into panel internals in the next one, and an
        // update is the path that would carry it in.
        $this->importScanner->assertOnlySdkImports($plans, (array) ($manifest->requirements['npmPackages'] ?? []));

        return $plans;
    }

    /**
     * Run migrations that are new in this version. Already-ran migrations are
     * skipped by filename, so re-running the package path applies only files
     * added since the previous release. A failure rolls the partial batch
     * back, writes a migration error log, and aborts the update.
     *
     * @param array<int, array<string, mixed>> $newFilePlans
     *
     * @return array<int, string> the migration files applied by this update
     */
    private function runNewMigrations(string $extensionId, array $newFilePlans): array
    {
        $migrationsPrefix = $this->migrationService->migrationPath($extensionId) . '/';
        $migrationFiles = [];
        foreach ($newFilePlans as $plan) {
            if (Str::startsWith($plan['path'], $migrationsPrefix)) {
                $migrationFiles[] = $plan['targetPath'];
            }
        }

        if ($migrationFiles === []) {
            return [];
        }

        $this->progressService->report('update', $extensionId, 'migrating');
        $this->migrationService->assertMigrationConventions($extensionId, $migrationFiles);

        try {
            $result = $this->migrationService->run($extensionId);
        } catch (\Throwable $exception) {
            try {
                $this->migrationService->rollbackLastBatch($extensionId);
            } catch (\Throwable $rollbackException) {
                report($rollbackException);
            }

            $logPath = $this->migrationService->writeMigrationLog(
                $extensionId,
                'update',
                ['migrations' => array_map('basename', $migrationFiles)],
                $exception
            );

            throw new DisplayException(sprintf('A migration shipped by the "%s" update failed and was rolled back. Details were written to %s.', $extensionId, $logPath), $exception);
        }

        return array_map('basename', $result['files']);
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

    private function assertSupportedArchiveArtifact(string $archivePath): void
    {
        if (!Str::endsWith(Str::lower($archivePath), ['.m12labsextension', '.zip'])) {
            throw new DisplayException('Manual updates expect a .M12LabsExtension package file. Legacy .zip artifacts are still supported for compatibility.');
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
            // because progress reporting or backup cleanup failed.
        }
    }
}
