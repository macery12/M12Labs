<?php

namespace Everest\Services\Extensions;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\ExtensionManifest;
use Everest\Services\Extensions\Manifest\ExtensionManifestParser;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityFileValidator;

class ExtensionPackageArtifactService
{
    public const MANIFEST_FILENAME = 'm12labs-extension.json';
    public const PACKAGE_ARTIFACT_FILENAME = 'package.M12LabsExtension';

    public function __construct(
        private PanelVersionCompatibilityService $panelVersionCompatibility,
        private ExtensionManifestParser $manifestParser,
        private ExtensionCapabilityFileValidator $capabilityFileValidator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectArchive(string $archivePath, ?string $workingDirectory = null): array
    {
        $resolvedPath = $this->resolveArchivePath($archivePath, $workingDirectory);

        $zip = new \ZipArchive();
        if ($zip->open($resolvedPath) !== true) {
            throw new DisplayException(sprintf('The extension package file "%s" could not be opened.', $resolvedPath));
        }

        try {
            $rawManifest = $zip->getFromName(self::MANIFEST_FILENAME);
            if (!is_string($rawManifest)) {
                throw new DisplayException(sprintf('The extension package "%s" does not contain %s.', basename($resolvedPath), self::MANIFEST_FILENAME));
            }

            $manifest = json_decode($rawManifest, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) {
                throw new DisplayException(sprintf('The extension package "%s" contains an invalid manifest.', basename($resolvedPath)));
            }
        } catch (\JsonException $exception) {
            throw new DisplayException(sprintf('The extension package "%s" contains malformed manifest JSON.', basename($resolvedPath)), $exception);
        } finally {
            $zip->close();
        }

        // Inspection runs the same strict validation an install does, so a
        // package that would be rejected later is rejected while it is still
        // just a file being listed, with the same message.
        $parsed = $this->parseManifest($manifest);

        return [
            'archivePath' => $resolvedPath,
            'archiveName' => basename($resolvedPath),
            'extensionId' => $parsed->id,
            'packageId' => $parsed->packageId,
            'version' => $parsed->version,
            'name' => $parsed->name,
            'description' => $parsed->description,
            'fileCount' => count($parsed->files),
            'compatiblePanelVersions' => $parsed->compatiblePanelVersions,
            'capabilities' => $parsed->capabilities->summary(),
            'parsed' => $parsed,
            'manifest' => $manifest,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function discoverArchives(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $archives = [];
        $entries = scandir($directory) ?: [];
        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = rtrim($directory, '/') . '/' . $entry;
            if (!is_file($path) || !$this->isSupportedArchiveName($path)) {
                continue;
            }

            try {
                $archives[] = $this->inspectArchive($path, $directory);
            } catch (\Throwable $exception) {
                $archives[] = [
                    'archivePath' => realpath($path) ?: $path,
                    'archiveName' => basename($path),
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $archives;
    }

    public function looksLikeArchiveReference(string $value, ?string $workingDirectory = null): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if ($this->isSupportedArchiveName($value)) {
            return true;
        }

        if (Str::startsWith($value, 'file://')) {
            return true;
        }

        if (Str::contains($value, ['/', '\\'])) {
            return true;
        }

        if ($workingDirectory) {
            $candidate = rtrim($workingDirectory, '/') . '/' . $value;

            return is_file($candidate) && $this->isSupportedArchiveName($candidate);
        }

        return false;
    }

    public function resolveArchivePath(string $archivePath, ?string $workingDirectory = null): string
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
            if ($workingDirectory) {
                $candidates[] = rtrim($workingDirectory, '/') . '/' . $archivePath;
            }

            $candidates[] = base_path($archivePath);
        }

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved && is_file($resolved) && $this->isSupportedArchiveName($resolved)) {
                return $resolved;
            }
        }

        throw new DisplayException(sprintf('The extension package file "%s" was not found.', $archivePath));
    }

    // ---------------------------------------------------------------------------
    // Shared archive helpers — used by install and update services
    // ---------------------------------------------------------------------------

    public function downloadArchive(string $location, string $destination): void
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

    public function verifyChecksum(string $path, string $expectedChecksum, string $label): void
    {
        if (hash_file('sha256', $path) !== $expectedChecksum) {
            throw new DisplayException(sprintf('The %s checksum did not match the manifest.', $label));
        }
    }

    public function extractArchive(string $archivePath, string $extractPath): void
    {
        $zip = new \ZipArchive();
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
    public function readPackageManifest(string $extractPath): array
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
     * The highest manifest schema version this panel understands.
     * v1: server-page extensions (implicit). v2 adds the admin page surface,
     * database migrations, scheduled tasks, and admin API routes.
     */
    public const SUPPORTED_MANIFEST_VERSION = 2;

    /**
     * Parse and fully validate a package manifest.
     *
     * Everything downstream consumes the returned object rather than the raw
     * array: the parser is the only place that reads manifest keys, so a shape
     * change lands in one file. Validation covers the document (strict schema,
     * closed vocabularies, namespaced identity) and its agreement with the
     * shipped file list in both directions.
     *
     * @param array<string, mixed> $manifest
     *
     * @throws DisplayException
     */
    public function parseManifest(array $manifest, ?string $expectedExtensionId = null, ?string $expectedVersion = null): ExtensionManifest
    {
        $parsed = $this->manifestParser->parse($manifest, $expectedExtensionId, $expectedVersion);

        $this->capabilityFileValidator->assertMatchesFiles($parsed);

        return $parsed;
    }

    /**
     * Whether the running panel satisfies an extension's declared compatibility.
     * An empty list means "no constraint" (always compatible). The single source
     * of truth for compatibility — used both to gate installs and to surface the
     * "incompatible" state in the catalog before an install is attempted.
     *
     * @param array<int, string> $versions
     */
    public function isCompatiblePanelVersions(array $versions): bool
    {
        return $this->panelVersionCompatibility->satisfiedBy(
            (string) config('app.version'),
            array_values(array_filter($versions, 'is_string'))
        );
    }

    /**
     * @param array<int, string> $versions
     */
    public function assertCompatiblePanelVersions(array $versions): void
    {
        if ($this->isCompatiblePanelVersions($versions)) {
            return;
        }

        throw new DisplayException(sprintf('This extension package supports M12Labs panel versions %s (exact versions or semver ranges). The current panel version is %s.', implode(', ', array_values(array_filter($versions, 'is_string'))), (string) config('app.version')));
    }

    public function normalizeTargetPath(string $path, string $extensionId): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $normalized = trim($normalized, '/');

        if ($normalized === '' || Str::contains($normalized, ['../', '..\\']) || Str::startsWith($normalized, '/')) {
            throw new DisplayException('The extension package includes an unsafe target path.');
        }

        $allowedPrefixes = [
            sprintf('app/Extensions/Packages/%s/', $extensionId),
            sprintf('frontend/src/extensions/packages/%s/', $extensionId),
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (Str::startsWith($normalized, $prefix)) {
                return $normalized;
            }
        }

        throw new DisplayException(sprintf('The package target path "%s" is not allowed by M12Labs.', $normalized));
    }

    private function isSupportedArchiveName(string $path): bool
    {
        return Str::endsWith(Str::lower($path), ['.m12labsextension', '.zip']);
    }
}
