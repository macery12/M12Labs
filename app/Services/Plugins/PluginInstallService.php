<?php

namespace Everest\Services\Plugins;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Everest\Models\Setting;
use Everest\Models\Server;
use Everest\Models\Billing\Product;
use Everest\Repositories\Wings\DaemonFileRepository;
use Everest\Services\Plugins\Adapters\SpigetProviderAdapter;
use Everest\Services\Plugins\Adapters\ModrinthProviderAdapter;
use Everest\Services\Plugins\Adapters\CurseForgeProviderAdapter;
use Everest\Exceptions\Service\Mods\ModsServiceException;

class PluginInstallService
{
    private array $adapters;
    private array $allowedExtensions = [
        'mod' => ['jar', 'zip'],
        'plugin' => ['jar'],
    ];
    private const DEFAULT_MAX_PLUGIN_SIZE = 104857600; // 100MB
    private const DEFAULT_MAX_MOD_SIZE = 157286400; // 150MB

    public function __construct(
        CurseForgeProviderAdapter $curseForgeProviderAdapter,
        ModrinthProviderAdapter $modrinthProviderAdapter,
        SpigetProviderAdapter $spigetProviderAdapter,
        private DaemonFileRepository $fileRepository
    ) {
        $this->adapters = [
            'curseforge' => $curseForgeProviderAdapter,
            'modrinth' => $modrinthProviderAdapter,
            'spiget' => $spigetProviderAdapter,
        ];
    }

    /**
     * Install an addon/plugin from a provider.
     *
     * @throws ModsServiceException
     */
    public function installFromProvider(Server $server, string $provider, string $type, string|int $projectId, string|int $versionId): array
    {
        $providerKey = strtolower($provider);
        $adapter = $this->adapters[$providerKey] ?? null;

        if (!$adapter) {
            throw new ModsServiceException('Unsupported provider selected.');
        }

        $this->validateProviderEnabled($providerKey);
        $this->validateEggSupport($server, $type);

        $download = $adapter->getDownloadUrl($projectId, $versionId);

        $fileName = $this->normalizeFileName($download['fileName'] ?? null, $projectId, $versionId, $type);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $this->validateExtension($extension, $type);

        $downloadUrl = $download['url'] ?? null;
        if (empty($downloadUrl)) {
            throw new ModsServiceException('Unable to resolve download URL for the selected file.');
        }

        $maxSize = $this->getMaxSizeForType($type);

        $tempPath = storage_path('app/temp/addon_' . uniqid() . '.' . $extension);
        $tempDir = dirname($tempPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $fileHandle = fopen($tempPath, 'w');
        if (!$fileHandle) {
            throw new ModsServiceException('Failed to create temporary file for download.');
        }

        try {
            $response = Http::timeout(300)->sink($fileHandle)->get($downloadUrl);

            if (!$response->successful()) {
                throw new ModsServiceException('Failed to download file from provider.');
            }

            $downloadedSize = filesize($tempPath);
            $sizeFromProvider = $download['fileSize'] ?? null;
            $effectiveSize = $sizeFromProvider && $sizeFromProvider > 0 ? $sizeFromProvider : $downloadedSize;

            if ($maxSize && $effectiveSize && $effectiveSize > $maxSize) {
                @unlink($tempPath);
                throw new ModsServiceException('Downloaded file exceeds maximum allowed size.');
            }

            $targetPath = $this->determineInstallPath($type, $fileName);
            $this->createTargetDirectory($server, $type);

            $content = file_get_contents($tempPath);
            $this->fileRepository->setServer($server)->putContent($targetPath, $content);
        } catch (\Exception $e) {
            Log::error('PluginInstallService download error: ' . $e->getMessage());
            throw $e instanceof ModsServiceException ? $e : new ModsServiceException('An unexpected error occurred while downloading the file.');
        } finally {
            if (is_resource($fileHandle)) {
                fclose($fileHandle);
            }
            @unlink($tempPath);
        }

        return [
            'success' => true,
            'message' => 'File downloaded and uploaded successfully.',
            'file' => [
                'name' => $fileName,
                'path' => $this->determineInstallPath($type, $fileName),
            ],
        ];
    }

    private function normalizeFileName(?string $fileName, string|int $projectId, string|int $versionId, string $type): string
    {
        $clean = $fileName ?: ($type . '_' . $projectId . '_' . $versionId . '.jar');
        $clean = str_replace(['\\', '/'], '-', $clean);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $clean) ?: ($type . '_' . $versionId . '.jar');

        if (!str_contains($clean, '.')) {
            $clean .= '.jar';
        }

        return $clean;
    }

    private function validateExtension(string $extension, string $type): void
    {
        $allowed = $this->allowedExtensions[$type] ?? [];
        if (!in_array($extension, $allowed, true)) {
            throw new ModsServiceException('The selected file type is not allowed for this install.');
        }
    }

    private function determineInstallPath(string $type, string $fileName): string
    {
        if ($type === 'plugin') {
            return '/plugins/' . $fileName;
        }

        return '/mods/' . $fileName;
    }

    private function createTargetDirectory(Server $server, string $type): void
    {
        $directory = $type === 'plugin' ? 'plugins' : 'mods';

        try {
            $this->fileRepository->setServer($server)->createDirectory($directory, '/');
        } catch (\Exception $e) {
            // Directory may already exist; ignore.
            Log::info('Install directory creation skipped: ' . $e->getMessage());
        }
    }

    private function validateProviderEnabled(string $provider): void
    {
        $modsEnabled = (bool) Setting::get('settings::modules:mods:enabled', config('modules.mods.enabled', false));
        $curseforgeKey = Setting::get('settings::modules:mods:curseforge_api_key', config('modules.mods.curseforge_api_key'));

        if (!$modsEnabled) {
            throw new ModsServiceException('Mods module is not enabled.');
        }

        if ($provider === 'curseforge' && empty($curseforgeKey)) {
            throw new ModsServiceException('CurseForge is not configured.');
        }
    }

    private function validateEggSupport(Server $server, string $type): void
    {
        if ($type !== 'plugin') {
            return;
        }

        $egg = $server->egg;
        if (!$egg) {
            throw new ModsServiceException('Unable to determine server egg for plugin installs.');
        }

        /** @var Product|null $product */
        $product = $server->product()->with('category')->first();
        $allowedEggs = $product?->category?->getAllowedEggs() ?? [];

        if (!empty($allowedEggs) && !in_array($egg->id, $allowedEggs)) {
            throw new ModsServiceException('Plugins can only be installed on permitted eggs for this server.');
        }
    }

    /**
     * Determine the maximum allowed download size (in bytes) for the provided install type.
     */
    private function getMaxSizeForType(string $type): ?int
    {
        return match ($type) {
            'plugin' => (int) config('modules.mods.max_plugin_size', self::DEFAULT_MAX_PLUGIN_SIZE),
            default => (int) config('modules.mods.max_mod_size', self::DEFAULT_MAX_MOD_SIZE),
        };
    }
}
