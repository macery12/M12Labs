<?php

namespace Everest\Services\Extensions;

use Everest\Exceptions\DisplayException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ZipArchive;

class ExtensionPackageArtifactService
{
    private const MANIFEST_FILENAME = 'm12labs-extension.json';

    /**
     * @return array<string, mixed>
     */
    public function inspectArchive(string $archivePath, ?string $workingDirectory = null): array
    {
        $resolvedPath = $this->resolveArchivePath($archivePath, $workingDirectory);

        $zip = new ZipArchive();
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

        $extensionId = trim((string) Arr::get($manifest, 'extension.id', ''));
        $version = trim((string) Arr::get($manifest, 'package.version', ''));

        if ($extensionId === '' || $version === '') {
            throw new DisplayException(sprintf('The extension package "%s" is missing extension.id or package.version.', basename($resolvedPath)));
        }

        return [
            'archivePath' => $resolvedPath,
            'archiveName' => basename($resolvedPath),
            'extensionId' => $extensionId,
            'packageId' => trim((string) Arr::get($manifest, 'package.id', $extensionId)),
            'version' => $version,
            'name' => trim((string) Arr::get($manifest, 'extension.name', $extensionId)),
            'description' => trim((string) Arr::get($manifest, 'extension.description', '')),
            'route' => trim((string) Arr::get($manifest, 'extension.route', $extensionId)),
            'fileCount' => count((array) Arr::get($manifest, 'files', [])),
            'compatiblePanelVersions' => array_values(array_filter((array) Arr::get($manifest, 'compatiblePanelVersions', []), 'is_string')),
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

        if (Str::contains($value, ['/','\\'])) {
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

    private function isSupportedArchiveName(string $path): bool
    {
        return Str::endsWith(Str::lower($path), ['.m12labsextension', '.zip']);
    }
}