<?php

namespace Everest\Services\Mods;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Everest\Exceptions\Service\Mods\ModsServiceException;

class ModLoaderInstallerService
{
    private string $installerCachePath;

    public function __construct()
    {
        $this->installerCachePath = storage_path('app/mod_loaders');
        if (!is_dir($this->installerCachePath)) {
            mkdir($this->installerCachePath, 0755, true);
        }
    }

    /**
     * Download the appropriate mod loader installer based on manifest data.
     *
     * @param array $manifest The modpack manifest.json data
     * @throws ModsServiceException
     * @return array Download result with installer path and version info
     */
    public function downloadModLoaderInstaller(array $manifest): array
    {
        // Extract Minecraft version
        $minecraftVersion = $manifest['minecraft']['version'] ?? null;
        if (!$minecraftVersion) {
            throw new ModsServiceException('Minecraft version not found in manifest');
        }

        // Extract mod loaders
        $modLoaders = $manifest['minecraft']['modLoaders'] ?? [];
        if (empty($modLoaders)) {
            throw new ModsServiceException('No mod loaders found in manifest');
        }

        // Find the primary loader or use the first one
        $selectedLoader = null;
        foreach ($modLoaders as $loader) {
            if (isset($loader['primary']) && $loader['primary']) {
                $selectedLoader = $loader;
                break;
            }
        }
        if (!$selectedLoader) {
            $selectedLoader = $modLoaders[0];
        }

        $loaderId = $selectedLoader['id'] ?? null;
        if (!$loaderId) {
            throw new ModsServiceException('Mod loader ID not found');
        }

        // Parse loader type and version from ID (e.g., "forge-47.2.0", "fabric-0.15.3")
        $parts = explode('-', $loaderId, 2);
        if (count($parts) !== 2) {
            throw new ModsServiceException("Invalid mod loader ID format: {$loaderId}");
        }

        $loaderType = strtolower($parts[0]);
        $loaderVersion = $parts[1];

        Log::info("Downloading mod loader installer: {$loaderType} {$loaderVersion} for Minecraft {$minecraftVersion}");

        // Download based on loader type
        switch ($loaderType) {
            case 'forge':
                $installerPath = $this->downloadForgeInstaller($minecraftVersion, $loaderVersion);
                break;
            case 'neoforge':
                $installerPath = $this->downloadNeoForgeInstaller($minecraftVersion, $loaderVersion);
                break;
            case 'fabric':
                $installerPath = $this->downloadFabricInstaller($minecraftVersion, $loaderVersion);
                break;
            case 'quilt':
                $installerPath = $this->downloadQuiltInstaller($minecraftVersion, $loaderVersion);
                break;
            default:
                throw new ModsServiceException("Unsupported mod loader type: {$loaderType}");
        }

        return [
            'loader_type' => $loaderType,
            'loader_version' => $loaderVersion,
            'minecraft_version' => $minecraftVersion,
            'installer_path' => $installerPath,
            'installer_filename' => basename($installerPath),
        ];
    }

    /**
     * Download Forge installer.
     */
    private function downloadForgeInstaller(string $minecraftVersion, string $loaderVersion): string
    {
        $installerFileName = "forge-{$minecraftVersion}-{$loaderVersion}-installer.jar";
        $installerPath = $this->installerCachePath . '/' . $installerFileName;

        // Download installer if not cached
        if (!file_exists($installerPath)) {
            Log::info("Downloading Forge installer: {$installerFileName}");
            
            // Forge Maven URL format
            $downloadUrl = "https://maven.minecraftforge.net/net/minecraftforge/forge/{$minecraftVersion}-{$loaderVersion}/forge-{$minecraftVersion}-{$loaderVersion}-installer.jar";
            
            $this->downloadInstaller($downloadUrl, $installerPath);
        } else {
            Log::info("Using cached Forge installer: {$installerFileName}");
        }

        return $installerPath;
    }

    /**
     * Download NeoForge installer.
     */
    private function downloadNeoForgeInstaller(string $minecraftVersion, string $loaderVersion): string
    {
        $installerFileName = "neoforge-{$loaderVersion}-installer.jar";
        $installerPath = $this->installerCachePath . '/' . $installerFileName;

        // Download installer if not cached
        if (!file_exists($installerPath)) {
            Log::info("Downloading NeoForge installer: {$installerFileName}");
            
            // NeoForge Maven URL format
            $downloadUrl = "https://maven.neoforged.net/releases/net/neoforged/neoforge/{$loaderVersion}/neoforge-{$loaderVersion}-installer.jar";
            
            $this->downloadInstaller($downloadUrl, $installerPath);
        } else {
            Log::info("Using cached NeoForge installer: {$installerFileName}");
        }

        return $installerPath;
    }

    /**
     * Download Fabric installer.
     */
    private function downloadFabricInstaller(string $minecraftVersion, string $loaderVersion): string
    {
        $installerFileName = "fabric-installer-1.0.1.jar";
        $installerPath = $this->installerCachePath . '/' . $installerFileName;

        // Download installer if not cached
        if (!file_exists($installerPath)) {
            Log::info("Downloading Fabric installer");
            
            // Fabric installer download URL
            $downloadUrl = "https://maven.fabricmc.net/net/fabricmc/fabric-installer/1.0.1/fabric-installer-1.0.1.jar";
            
            $this->downloadInstaller($downloadUrl, $installerPath);
        } else {
            Log::info("Using cached Fabric installer");
        }

        return $installerPath;
    }

    /**
     * Download Quilt installer.
     */
    private function downloadQuiltInstaller(string $minecraftVersion, string $loaderVersion): string
    {
        $installerFileName = "quilt-installer-latest.jar";
        $installerPath = $this->installerCachePath . '/' . $installerFileName;

        // Download installer if not cached
        if (!file_exists($installerPath)) {
            Log::info("Downloading Quilt installer");
            
            // Quilt installer download URL
            $downloadUrl = "https://maven.quiltmc.org/repository/release/org/quiltmc/quilt-installer/latest/quilt-installer-latest.jar";
            
            $this->downloadInstaller($downloadUrl, $installerPath);
        } else {
            Log::info("Using cached Quilt installer");
        }

        return $installerPath;
    }

    /**
     * Download installer JAR file.
     */
    private function downloadInstaller(string $url, string $destinationPath): void
    {
        try {
            $response = Http::timeout(120)->get($url);
            
            if (!$response->successful()) {
                throw new ModsServiceException("Failed to download installer from {$url}. HTTP {$response->status()}");
            }
            
            file_put_contents($destinationPath, $response->body());
            
            if (!file_exists($destinationPath) || filesize($destinationPath) === 0) {
                throw new ModsServiceException("Installer download failed or file is empty");
            }
            
            Log::info("Installer downloaded successfully to {$destinationPath}");
        } catch (\Exception $e) {
            throw new ModsServiceException("Failed to download mod loader installer: " . $e->getMessage());
        }
    }
}
