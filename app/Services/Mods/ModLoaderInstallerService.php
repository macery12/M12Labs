<?php

namespace Everest\Services\Mods;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
     * Install the appropriate mod loader based on manifest data.
     *
     * @param array $manifest The modpack manifest.json data
     * @param string $serverPath The path to the server directory
     * @throws ModsServiceException
     * @return array Installation result with details
     */
    public function installModLoader(array $manifest, string $serverPath): array
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

        Log::info("Installing mod loader: {$loaderType} {$loaderVersion} for Minecraft {$minecraftVersion}");

        // Install based on loader type
        switch ($loaderType) {
            case 'forge':
                return $this->installForge($minecraftVersion, $loaderVersion, $serverPath);
            case 'neoforge':
                return $this->installNeoForge($minecraftVersion, $loaderVersion, $serverPath);
            case 'fabric':
                return $this->installFabric($minecraftVersion, $loaderVersion, $serverPath);
            case 'quilt':
                return $this->installQuilt($minecraftVersion, $loaderVersion, $serverPath);
            default:
                throw new ModsServiceException("Unsupported mod loader type: {$loaderType}");
        }
    }

    /**
     * Install Forge mod loader.
     */
    private function installForge(string $minecraftVersion, string $loaderVersion, string $serverPath): array
    {
        $installerFileName = "forge-{$minecraftVersion}-{$loaderVersion}-installer.jar";
        $installerPath = $this->installerCachePath . '/' . $installerFileName;

        // Download installer if not cached
        if (!file_exists($installerPath)) {
            Log::info("Downloading Forge installer: {$installerFileName}");
            
            // Forge Maven URL format: https://maven.minecraftforge.net/net/minecraftforge/forge/{mc-version}-{forge-version}/forge-{mc-version}-{forge-version}-installer.jar
            $downloadUrl = "https://maven.minecraftforge.net/net/minecraftforge/forge/{$minecraftVersion}-{$loaderVersion}/forge-{$minecraftVersion}-{$loaderVersion}-installer.jar";
            
            $this->downloadInstaller($downloadUrl, $installerPath);
        } else {
            Log::info("Using cached Forge installer: {$installerFileName}");
        }

        // Run installer
        return $this->runForgeInstaller($installerPath, $serverPath, 'Forge');
    }

    /**
     * Install NeoForge mod loader.
     */
    private function installNeoForge(string $minecraftVersion, string $loaderVersion, string $serverPath): array
    {
        $installerFileName = "neoforge-{$loaderVersion}-installer.jar";
        $installerPath = $this->installerCachePath . '/' . $installerFileName;

        // Download installer if not cached
        if (!file_exists($installerPath)) {
            Log::info("Downloading NeoForge installer: {$installerFileName}");
            
            // NeoForge Maven URL format: https://maven.neoforged.net/releases/net/neoforged/neoforge/{version}/neoforge-{version}-installer.jar
            $downloadUrl = "https://maven.neoforged.net/releases/net/neoforged/neoforge/{$loaderVersion}/neoforge-{$loaderVersion}-installer.jar";
            
            $this->downloadInstaller($downloadUrl, $installerPath);
        } else {
            Log::info("Using cached NeoForge installer: {$installerFileName}");
        }

        // Run installer (same process as Forge)
        return $this->runForgeInstaller($installerPath, $serverPath, 'NeoForge');
    }

    /**
     * Install Fabric mod loader.
     */
    private function installFabric(string $minecraftVersion, string $loaderVersion, string $serverPath): array
    {
        $installerFileName = "fabric-installer-latest.jar";
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

        // Run Fabric installer
        return $this->runFabricInstaller($installerPath, $serverPath, $minecraftVersion, $loaderVersion);
    }

    /**
     * Install Quilt mod loader.
     */
    private function installQuilt(string $minecraftVersion, string $loaderVersion, string $serverPath): array
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

        // Run Quilt installer (similar to Fabric)
        return $this->runFabricInstaller($installerPath, $serverPath, $minecraftVersion, $loaderVersion);
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

    /**
     * Run Forge/NeoForge installer.
     */
    private function runForgeInstaller(string $installerPath, string $serverPath, string $loaderName): array
    {
        Log::info("Running {$loaderName} installer at {$serverPath}");
        
        // Ensure server directory exists
        if (!is_dir($serverPath)) {
            mkdir($serverPath, 0755, true);
        }

        // Run the installer with --installServer flag
        $command = sprintf(
            'cd %s && java -jar %s --installServer 2>&1',
            escapeshellarg($serverPath),
            escapeshellarg($installerPath)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);
        Log::info("{$loaderName} installer output: " . $outputStr);

        if ($returnCode !== 0) {
            throw new ModsServiceException("{$loaderName} installer failed with code {$returnCode}: {$outputStr}");
        }

        // Verify installation
        $this->verifyForgeInstallation($serverPath, $loaderName);

        return [
            'loader' => $loaderName,
            'success' => true,
            'output' => $outputStr,
        ];
    }

    /**
     * Run Fabric/Quilt installer.
     */
    private function runFabricInstaller(string $installerPath, string $serverPath, string $minecraftVersion, string $loaderVersion): array
    {
        $loaderName = basename($installerPath, '.jar') === 'fabric-installer-latest' ? 'Fabric' : 'Quilt';
        Log::info("Running {$loaderName} installer at {$serverPath}");
        
        // Ensure server directory exists
        if (!is_dir($serverPath)) {
            mkdir($serverPath, 0755, true);
        }

        // Run the installer with server install mode
        $command = sprintf(
            'cd %s && java -jar %s server -mcversion %s -loader %s -downloadMinecraft 2>&1',
            escapeshellarg($serverPath),
            escapeshellarg($installerPath),
            escapeshellarg($minecraftVersion),
            escapeshellarg($loaderVersion)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);
        Log::info("{$loaderName} installer output: " . $outputStr);

        if ($returnCode !== 0) {
            throw new ModsServiceException("{$loaderName} installer failed with code {$returnCode}: {$outputStr}");
        }

        // Verify installation
        $this->verifyFabricInstallation($serverPath, $loaderName);

        return [
            'loader' => $loaderName,
            'success' => true,
            'output' => $outputStr,
        ];
    }

    /**
     * Verify Forge/NeoForge installation.
     */
    private function verifyForgeInstallation(string $serverPath, string $loaderName): void
    {
        // Check for server jar or run.sh/run.bat
        $serverJarExists = false;
        $files = scandir($serverPath);
        
        foreach ($files as $file) {
            if (preg_match('/\.jar$/', $file) && (
                strpos($file, 'forge') !== false || 
                strpos($file, 'neoforge') !== false ||
                strpos($file, 'server') !== false
            )) {
                $serverJarExists = true;
                break;
            }
        }

        if (!$serverJarExists) {
            throw new ModsServiceException("{$loaderName} installation verification failed: No server JAR found");
        }

        // Check for libraries directory
        if (!is_dir($serverPath . '/libraries')) {
            throw new ModsServiceException("{$loaderName} installation verification failed: Libraries directory not found");
        }

        Log::info("{$loaderName} installation verified successfully");
    }

    /**
     * Verify Fabric/Quilt installation.
     */
    private function verifyFabricInstallation(string $serverPath, string $loaderName): void
    {
        // Check for server launcher jar
        $launcherExists = false;
        $files = scandir($serverPath);
        
        foreach ($files as $file) {
            if (preg_match('/fabric.*server.*launch.*\.jar$|quilt.*server.*launch.*\.jar$/i', $file)) {
                $launcherExists = true;
                break;
            }
        }

        if (!$launcherExists) {
            throw new ModsServiceException("{$loaderName} installation verification failed: No launcher JAR found");
        }

        // Check for libraries directory
        if (!is_dir($serverPath . '/libraries')) {
            throw new ModsServiceException("{$loaderName} installation verification failed: Libraries directory not found");
        }

        Log::info("{$loaderName} installation verified successfully");
    }
}
