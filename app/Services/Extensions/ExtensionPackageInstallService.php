<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Everest\Models\ExtensionConfig;
use Everest\Models\ExtensionPackage;
use Everest\Models\ExtensionPackageFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

class ExtensionPackageInstallService
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

    public function install(string $extensionId, int $repositoryId, ?string $version = null): ExtensionPackage
    {
        return $this->operationLockService->withinLock('install', $extensionId, function () use ($extensionId, $repositoryId, $version) {
            $package = $this->catalogService->findRepositoryPackage($extensionId, $repositoryId, $version);
            $release = $package['latestRelease'];

            return $this->installFromSource(
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
        });
    }

    public function installFromArchive(string $archivePath, ?string $sourceLabel = null): ExtensionPackage
    {
        $resolvedArchivePath = $this->resolveLocalArchivePath($archivePath);
        $this->assertSupportedArchiveArtifact($resolvedArchivePath);

        return $this->operationLockService->withinLock('install', basename($resolvedArchivePath), function () use ($resolvedArchivePath, $sourceLabel) {
            return $this->installFromSource(
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
            );
        });
    }

    /**
     * @param array<string, mixed> $fallbackPackageMetadata
     * @param array<int, string> $compatiblePanelVersions
     */
    private function installFromSource(
        string $archiveLocation,
        ?string $expectedExtensionId,
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
        $appliedFiles = [];
        $resolvedExtensionId = $expectedExtensionId;

        File::ensureDirectoryExists($tempRoot);
        File::ensureDirectoryExists($extractPath);

        try {
            $this->progressService->report('install', $resolvedExtensionId ?? 'unknown', 'downloading');
            $this->downloadArchive($archiveLocation, $archivePath);

            $this->progressService->report('install', $resolvedExtensionId ?? 'unknown', 'extracting');
            $archiveChecksum = hash_file('sha256', $archivePath);
            if ($expectedArchiveChecksum !== null) {
                $this->verifyChecksum($archivePath, $expectedArchiveChecksum, 'archive');
            }
            $this->extractArchive($archivePath, $extractPath);

            $this->progressService->report('install', $resolvedExtensionId ?? 'unknown', 'validating');
            $manifest = $this->readPackageManifest($extractPath);
            $normalizedManifest = $this->normalizeManifest($manifest, $expectedExtensionId, $expectedVersion);
            $extensionId = (string) Arr::get($normalizedManifest, 'extension.id');
            $resolvedExtensionId = $extensionId;
            $backupRoot = storage_path('app/extensions/backups/' . $extensionId . '/' . Str::uuid()->toString());

            $this->assertExtensionNotInstalled($extensionId);
            $this->assertCompatiblePanelVersions($compatiblePanelVersions);
            $this->assertCompatiblePanelVersions(Arr::get($normalizedManifest, 'compatiblePanelVersions', []));
            $this->ownershipService->repairStandardPaths($extensionId);

            $filePlans = $this->prepareFilePlans($extractPath, $normalizedManifest, $backupRoot, $extensionId);
            $this->assertWritableInstallTargets($filePlans);

            $this->progressService->report('install', $extensionId, 'copying');
            foreach ($filePlans as $plan) {
                File::ensureDirectoryExists(dirname($plan['targetPath']));
                File::copy($plan['sourcePath'], $plan['targetPath']);
                $appliedFiles[] = $plan;
            }

            // Validate (and auto-repair when root) workspace ownership before the
            // build runs so any ownership problem surfaces here with a clear error
            // rather than as a cryptic mid-build failure.
            $this->progressService->report('install', $extensionId, 'ownership');
            $this->ownershipService->validateBuildWorkspaceOwnership();

            // Report 'optimizing' before optimize:clear and 'building' before pnpm/npm.
            // The callback fires just before each sub-command so the file-based progress
            // is written after optimize:clear has already cleared the application cache.
            $this->rebuildService->rebuild(
                sprintf('Install extension %s', $extensionId),
                function (int $index) use ($extensionId): void {
                    $this->progressService->report(
                        'install',
                        $extensionId,
                        $index === 0 ? 'optimizing' : 'building'
                    );
                }
            );

            $this->progressService->report('install', $extensionId, 'registering');
            $packageModel = DB::transaction(function () use (
                $archiveChecksum,
                $extensionId,
                $fallbackPackageMetadata,
                $filePlans,
                $normalizedManifest,
                $sourceArchiveUrl,
                $sourceRegistryUrl,
                $sourceRepositoryId,
                $sourceRepositoryName
            ) {
                return $this->persistInstalledPackage(
                    extensionId: $extensionId,
                    normalizedManifest: $normalizedManifest,
                    fallbackPackageMetadata: $fallbackPackageMetadata,
                    filePlans: $filePlans,
                    sourceRepositoryId: $sourceRepositoryId,
                    sourceRepositoryName: $sourceRepositoryName,
                    sourceRegistryUrl: $sourceRegistryUrl,
                    sourceArchiveUrl: $sourceArchiveUrl,
                    archiveChecksum: is_string($archiveChecksum) ? $archiveChecksum : null,
                );
            });

            $this->progressService->report('install', $extensionId, 'completed');

            return $packageModel->fresh(['repository', 'files']);
        } catch (\Throwable $exception) {
            $this->rollbackAppliedFiles($appliedFiles);
            $this->attemptRollbackRebuild($resolvedExtensionId ?? 'extension-package', 'install rollback');

            if ($exception instanceof DisplayException) {
                throw $exception;
            }

            throw new DisplayException('Failed to install the selected extension package.', $exception);
        } finally {
            $this->progressService->clear();
            $this->ownershipService->repairStandardPaths($resolvedExtensionId);
            File::deleteDirectory($tempRoot);
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

    private function assertExtensionNotInstalled(string $extensionId): void
    {
        if (ExtensionPackage::query()->where('extension_id', $extensionId)->exists()) {
            throw new DisplayException('This extension is already installed. Uninstall it before installing it again.');
        }
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

        throw new DisplayException('Manual installs expect a .M12LabsExtension package file. Legacy .zip artifacts are still supported for compatibility.');
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
}