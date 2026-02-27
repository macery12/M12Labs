<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Everest\Models\Server;
use Everest\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Everest\Services\Mods\ModrinthService;
use Everest\Services\Mods\CurseForgeService;
use Everest\Services\Mods\SpigetService;
use Everest\Services\Plugins\ProviderAccessService;
use Everest\Repositories\Wings\DaemonFileRepository;
use Everest\Services\Plugins\PluginInstallService;
use Everest\Exceptions\Service\Mods\ModsServiceException;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Http\Requests\Api\Client\Servers\Mods\GetModRequest;
use Everest\Http\Requests\Api\Client\Servers\Mods\SearchModsRequest;
use Everest\Http\Requests\Api\Client\Servers\Mods\DownloadModRequest;
use Everest\Http\Requests\Api\Client\Servers\Mods\GetModFilesRequest;
use Everest\Http\Requests\Api\Client\Servers\Mods\GetMinecraftVersionsRequest;

class ModsController extends ClientApiController
{
    /**
     * ModsController constructor.
     */
    public function __construct(
        private CurseForgeService $curseForgeService,
        private ModrinthService $modrinthService,
        private SpigetService $spigetService,
        private PluginInstallService $pluginInstallService,
        private DaemonFileRepository $fileRepository,
        private ProviderAccessService $providerAccessService
    ) {
        parent::__construct();
    }

    /**
     * Get the mod service based on the request source parameter.
     */
    private function getModService(string $source = null): CurseForgeService|ModrinthService|SpigetService
    {
        $source = $source ?? Setting::get('settings::modules:mods:default_source', config('modules.mods.default_source', 'modrinth'));

        if ($source === 'curseforge') {
            return $this->curseForgeService;
        }

        if (in_array($source, ['spiget', 'spigot'], true)) {
            return $this->spigetService;
        }

        return $this->modrinthService;
    }

    /**
     * Determine provider key based on source and resource.
     */
    private function resolveProviderKey(?string $source, string $resource = 'mods'): string
    {
        if ($source === 'curseforge') {
            return 'curseforge';
        }

        if (in_array($source, ['spiget', 'spigot'], true)) {
            return 'spigot.plugins';
        }

        if ($source === 'modrinth' && $resource === 'mods') {
            return 'modrinth.mods';
        }

        return 'modrinth.mods';
    }

    private function denyResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Provider disabled',
            'reason' => "This provider is not enabled for this server (egg/nest policy).",
        ], 403);
    }

    private function checkProviderAllowed(Server $server, ?string $source, string $resource = 'mods'): ?JsonResponse
    {
        if (!$server->mods_enabled) {
            return $this->denyResponse();
        }

        $providerKey = $this->resolveProviderKey($source, $resource);

        if (!$this->providerAccessService->isProviderAllowed($providerKey, $server->nest_id, $server->egg_id)) {
            return $this->denyResponse();
        }

        return null;
    }

    public function providerAccess(Server $server): JsonResponse
    {
        $providers = [
            'modrinth.mods',
            'curseforge',
            'spigot.plugins',
        ];

        $result = [];
        foreach ($providers as $providerKey) {
            if (!$server->mods_enabled) {
                $result[$providerKey] = [
                    'allowed' => false,
                    'reason' => 'Plugins module is disabled for this server.',
                ];
                continue;
            }

            $result[$providerKey] = [
                'allowed' => $this->providerAccessService->isProviderAllowed($providerKey, $server->nest_id, $server->egg_id),
                'reason' => "Disabled by administrator for this server's egg/nest.",
            ];
        }

        return response()->json([
            'providers' => $result,
        ]);
    }

    /**
     * Search for mods in the selected database.
     *
     * @throws ModsServiceException
     */
    public function search(SearchModsRequest $request, Server $server): JsonResponse
    {
        if ($response = $this->checkProviderAllowed($server, $request->input('source'), 'mods')) {
            return $response;
        }

        $source = $request->input('source');
        $modService = $this->getModService($source);

        $params = array_filter([
            'searchFilter' => $request->input('searchFilter'),
            'sortField' => $request->input('sortField'),
            'sortOrder' => $request->input('sortOrder'),
            'gameVersion' => $request->input('gameVersion'),
            'modLoaderType' => $request->input('modLoaderType'),
            'pageSize' => $request->input('pageSize', 20),
            'index' => $request->input('index', 0),
            'categoryId' => $request->input('categoryId'),
            'minRating' => $request->input('minRating'),
        ], function ($value) {
            return $value !== null;
        });

        try {
            $result = $modService->searchMods($params);

            return response()->json($result);
        } catch (ModsServiceException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get details of a specific mod.
     *
     * @throws ModsServiceException
     */
    public function getMod(GetModRequest $request, Server $server, string $modId): JsonResponse
    {
        if ($response = $this->checkProviderAllowed($server, $request->input('source'), 'mods')) {
            return $response;
        }

        $source = $request->input('source');
        $modService = $this->getModService($source);

        try {
            $result = $modService->getMod($modId);

            return response()->json($result);
        } catch (ModsServiceException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get files for a specific mod.
     *
     * @throws ModsServiceException
     */
    public function getModFiles(GetModFilesRequest $request, Server $server, string $modId): JsonResponse
    {
        if ($response = $this->checkProviderAllowed($server, $request->input('source'), 'mods')) {
            return $response;
        }

        $source = $request->input('source');
        $modService = $this->getModService($source);

        $params = array_filter([
            'gameVersion' => $request->input('gameVersion'),
            'modLoaderType' => $request->input('modLoaderType'),
            'pageSize' => $request->input('pageSize', 20),
            'index' => $request->input('index', 0),
        ], function ($value) {
            return $value !== null;
        });

        try {
            $result = $modService->getModFiles($modId, $params);

            return response()->json($result);
        } catch (ModsServiceException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download a mod file and upload it to the server's /mods folder.
     *
     * @throws ModsServiceException
     */
    public function downloadMod(DownloadModRequest $request, Server $server, string $modId, string $fileId): JsonResponse
    {
        if ($response = $this->checkProviderAllowed($server, $request->input('source'), 'mods')) {
            return $response;
        }

        try {
            $source = $request->input('source') ?? Setting::get('settings::modules:mods:default_source', config('modules.mods.default_source', 'modrinth'));
            $type = in_array($source, ['spiget', 'spigot'], true) ? 'plugin' : 'mod';
            $result = $this->pluginInstallService->installFromProvider($server, $source, $type, $modId, $fileId);

            return response()->json($result);
        } catch (ModsServiceException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('Mod download failed: ' . $e->getMessage());

            return response()->json([
                'error' => 'An unexpected error occurred while downloading the mod.',
            ], 500);
        }
    }

    /**
     * Get available Minecraft versions.
     *
     * @throws ModsServiceException
     */
    public function getMinecraftVersions(GetMinecraftVersionsRequest $request, Server $server): JsonResponse
    {
        if ($response = $this->checkProviderAllowed($server, $request->input('source'), 'mods')) {
            return $response;
        }

        $source = $request->input('source');
        $modService = $this->getModService($source);

        try {
            $result = $modService->getMinecraftVersions();

            return response()->json($result);
        } catch (ModsServiceException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available mod loader types.
     *
     * @throws ModsServiceException
     */
    public function getModLoaderTypes(GetMinecraftVersionsRequest $request, Server $server): JsonResponse
    {
        if ($response = $this->checkProviderAllowed($server, $request->input('source'), 'mods')) {
            return $response;
        }

        $source = $request->input('source');
        $modService = $this->getModService($source);

        try {
            $result = $modService->getModLoaderTypes();

            return response()->json($result);
        } catch (ModsServiceException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search for modpacks in the CurseForge database.
     *
     * @throws ModsServiceException
     */
    public function searchModpacks(SearchModsRequest $request, Server $server): JsonResponse
    {
        if ($response = $this->checkProviderAllowed($server, 'curseforge', 'modpacks')) {
            return $response;
        }

        // Only accept valid modpack search parameters
        $params = array_filter([
            'searchFilter' => $request->input('searchFilter'),
            'sortField' => $request->input('sortField'),
            'sortOrder' => $request->input('sortOrder'),
            'pageSize' => $request->input('pageSize', 20),
            'index' => $request->input('index', 0),
        ], function ($value) {
            // Filter out null, empty strings, and ensure only valid values pass through
            return $value !== null && $value !== '';
        });

        try {
            $result = $this->curseForgeService->searchModpacks($params);

            return response()->json($result);
        } catch (ModsServiceException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get details of a specific modpack.
     *
     * @throws ModsServiceException
     */
    public function getModpack(GetModRequest $request, Server $server, int $modpackId): JsonResponse
    {
        if ($response = $this->checkProviderAllowed($server, 'curseforge', 'modpacks')) {
            return $response;
        }

        try {
            $result = $this->curseForgeService->getModpack($modpackId);

            return response()->json($result);
        } catch (ModsServiceException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get files for a specific modpack.
     *
     * @throws ModsServiceException
     */
    public function getModpackFiles(GetModFilesRequest $request, Server $server, int $modpackId): JsonResponse
    {
        if ($response = $this->checkProviderAllowed($server, 'curseforge', 'modpacks')) {
            return $response;
        }

        $params = array_filter([
            'gameVersion' => $request->input('gameVersion'),
            'modLoaderType' => $request->input('modLoaderType'),
            'pageSize' => $request->input('pageSize', 20),
            'index' => $request->input('index', 0),
        ], function ($value) {
            return $value !== null;
        });

        try {
            $result = $this->curseForgeService->getModpackFiles($modpackId, $params);

            return response()->json($result);
        } catch (ModsServiceException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download a modpack file and extract it to the server.
     *
     * @throws ModsServiceException
     */
    public function downloadModpack(DownloadModRequest $request, Server $server, int $modpackId, int $fileId): JsonResponse
    {
        if ($response = $this->checkProviderAllowed($server, 'curseforge', 'modpacks')) {
            return $response;
        }

        // Extend PHP execution time for large modpack downloads (10 minutes)
        // This can take a while with many mods to download and extract
        set_time_limit(600);

        try {
            // Get modpack file details
            $modpackFile = $this->curseForgeService->getModpackFile($modpackId, $fileId);

            if (!isset($modpackFile['data'])) {
                throw new ModsServiceException('Failed to retrieve modpack file details.');
            }

            $fileData = $modpackFile['data'];
            $fileName = $fileData['fileName'] ?? 'modpack.zip';
            $fileSize = $fileData['fileLength'] ?? 0;

            // Check file size limit (500MB max)
            $maxFileSize = config('modules.mods.max_modpack_size', 524288000); // 500MB default
            if ($fileSize > $maxFileSize) {
                throw new ModsServiceException('Modpack file size exceeds maximum allowed size.');
            }

            // Get download URL
            $downloadUrl = $this->curseForgeService->getModFileDownloadUrl($modpackId, $fileId);

            // Create temporary directory for extraction in a secure location
            $baseTempDir = storage_path('app/temp');
            if (!is_dir($baseTempDir)) {
                mkdir($baseTempDir, 0755, true);
            }

            $tempDir = $baseTempDir . '/modpack_' . uniqid('', true);
            mkdir($tempDir, 0755, true);
            $zipPath = $tempDir . '/modpack.zip';

            // Stream download to file to avoid loading entire file into memory
            try {
                $fileHandle = fopen($zipPath, 'w');
                if (!$fileHandle) {
                    throw new ModsServiceException('Failed to create temporary file for modpack download.');
                }

                $response = Http::timeout(300)->sink($fileHandle)->get($downloadUrl);
                // Note: sink() automatically closes the file handle, so we don't call fclose() here

                if (!$response->successful()) {
                    $this->deleteDirectory($tempDir);
                    throw new ModsServiceException('Failed to download modpack file from CurseForge.');
                }

                // Validate downloaded file size
                $downloadedSize = filesize($zipPath);
                if ($downloadedSize > $maxFileSize) {
                    $this->deleteDirectory($tempDir);
                    throw new ModsServiceException('Downloaded modpack exceeds maximum allowed size.');
                }
            } catch (\Exception $e) {
                // Only close if still a valid resource (sink may not have been called yet)
                if (isset($fileHandle) && is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
                $this->deleteDirectory($tempDir);
                throw $e;
            }

            // Extract the zip file with path traversal protection
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) === true) {
                // Validate all entries to prevent Zip Slip attacks
                $realTempDir = realpath($tempDir);
                if ($realTempDir === false) {
                    $zip->close();
                    $this->deleteDirectory($tempDir);
                    throw new ModsServiceException('Failed to resolve temporary directory path.');
                }

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    
                    // Resolve the full path and check if it's within the temp directory
                    $extractPath = $tempDir . '/' . $entry;
                    // Normalize path to prevent traversal
                    $normalizedPath = str_replace(['\\', '/./'], ['/', '/'], $extractPath);
                    $normalizedPath = preg_replace('#/+#', '/', $normalizedPath);
                    
                    // Check for path traversal attempts
                    if (strpos($normalizedPath, '../') !== false || strpos($entry, '../') !== false) {
                        $zip->close();
                        $this->deleteDirectory($tempDir);
                        throw new ModsServiceException('Modpack contains invalid file paths (path traversal detected).');
                    }
                    
                    // Additional check: ensure extracted path would be within temp directory
                    $parentDir = dirname($normalizedPath);
                    if ($parentDir !== $tempDir && strpos($parentDir, $realTempDir) !== 0) {
                        // For safety, also check if any parent directory escapes
                        $testPath = $normalizedPath;
                        while ($testPath !== '/' && $testPath !== '.') {
                            if (!str_starts_with($testPath, $realTempDir)) {
                                $zip->close();
                                $this->deleteDirectory($tempDir);
                                throw new ModsServiceException('Modpack contains paths outside allowed directory.');
                            }
                            $testPath = dirname($testPath);
                            if ($testPath === $tempDir || $testPath === $realTempDir) {
                                break;
                            }
                        }
                    }
                }

                // All entries validated, safe to extract
                $zip->extractTo($tempDir);
                $zip->close();
            } else {
                $this->deleteDirectory($tempDir);
                throw new ModsServiceException('Failed to extract modpack archive.');
            }

            // Parse manifest.json to get mod list
            $manifestPath = $tempDir . '/manifest.json';
            if (!file_exists($manifestPath)) {
                $this->deleteDirectory($tempDir);
                throw new ModsServiceException('Modpack manifest.json not found.');
            }

            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (!isset($manifest['files']) || !is_array($manifest['files'])) {
                $this->deleteDirectory($tempDir);
                throw new ModsServiceException('Invalid modpack manifest format.');
            }

            // Create /mods folder if it doesn't exist
            try {
                $this->fileRepository->setServer($server)->createDirectory('mods', '/');
            } catch (\Exception $e) {
                Log::info('Mods folder creation skipped: ' . $e->getMessage());
            }

            // Upload overrides folder if exists (configs, etc.)
            $overridesDir = $tempDir . '/overrides';
            if (is_dir($overridesDir)) {
                $this->uploadDirectoryRecursively($server, $overridesDir, '/');
            }

            // Download and install each mod from the manifest
            $downloadedMods = [];
            $failedMods = [];

            foreach ($manifest['files'] as $modEntry) {
                try {
                    $modFileId = $modEntry['fileID'];
                    $projectId = $modEntry['projectID'];

                    // Get the mod file download URL (cached, serialized API call)
                    $modDownloadUrl = $this->curseForgeService->getModFileDownloadUrl($projectId, $modFileId);

                    // Get mod file details to get the filename (cached, serialized API call)
                    $modFileDetails = $this->curseForgeService->getModFile($projectId, $modFileId);
                    $modFileName = $modFileDetails['data']['fileName'] ?? "mod_{$projectId}_{$modFileId}.jar";

                    // Download the mod file to a temporary location first to avoid memory issues
                    $tempModPath = $tempDir . '/temp_' . $modFileName;
                    $modFileHandle = fopen($tempModPath, 'w');

                    if (!$modFileHandle) {
                        Log::warning("Failed to create temp file for mod {$projectId}");
                        $failedMods[] = ['projectId' => $projectId, 'fileId' => $modFileId];
                        continue;
                    }

                    try {
                        $modResponse = Http::timeout(120)->sink($modFileHandle)->get($modDownloadUrl);
                        // Note: sink() automatically closes the file handle, so we don't call fclose() here

                        if ($modResponse->successful()) {
                            // Read and upload the file content
                            $modContent = file_get_contents($tempModPath);
                            $this->fileRepository->setServer($server)->putContent("/mods/{$modFileName}", $modContent);
                            $downloadedMods[] = $modFileName;

                            // Clean up temp file
                            @unlink($tempModPath);
                        } else {
                            @unlink($tempModPath);
                            $failedMods[] = ['projectId' => $projectId, 'fileId' => $modFileId];
                        }
                    } catch (\Exception $modEx) {
                        // Only close if still a valid resource (sink may not have been called yet)
                        if (is_resource($modFileHandle)) {
                            fclose($modFileHandle);
                        }
                        @unlink($tempModPath);
                        throw $modEx;
                    }
                } catch (ModsServiceException $e) {
                    Log::error("Failed to download mod {$modEntry['projectID']}: " . $e->getMessage());
                    $failedMods[] = ['projectId' => $modEntry['projectID'], 'fileId' => $modEntry['fileID']];
                } catch (\Exception $e) {
                    Log::error("Failed to download mod {$modEntry['projectID']}: " . $e->getMessage());
                    $failedMods[] = ['projectId' => $modEntry['projectID'], 'fileId' => $modEntry['fileID']];
                }
            }

            // Clean up temporary files
            $this->deleteDirectory($tempDir);

            return response()->json([
                'success' => true,
                'message' => 'Modpack downloaded and installed successfully.',
                'details' => [
                    'modpack' => $manifest['name'] ?? 'Unknown',
                    'version' => $manifest['version'] ?? 'Unknown',
                    'mods_downloaded' => count($downloadedMods),
                    'mods_failed' => count($failedMods),
                    'downloaded_mods' => $downloadedMods,
                    'failed_mods' => $failedMods,
                ],
            ]);
        } catch (ModsServiceException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('Modpack download failed: ' . $e->getMessage());

            return response()->json([
                'error' => 'An unexpected error occurred while downloading the modpack.',
            ], 500);
        }
    }

    /**
     * Recursively upload a directory to the server with path validation.
     */
    private function uploadDirectoryRecursively(Server $server, string $localPath, string $remotePath): void
    {
        $items = scandir($localPath);
        
        // Validate the base local path
        $realLocalPath = realpath($localPath);
        if ($realLocalPath === false) {
            throw new ModsServiceException('Invalid local path for upload.');
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            // Validate item name to prevent path traversal
            if (strpos($item, '..') !== false || strpos($item, '/') !== false || strpos($item, '\\') !== false) {
                Log::warning("Skipping potentially malicious file/directory name: {$item}");
                continue;
            }

            $localItemPath = $localPath . '/' . $item;
            
            // Ensure the resolved path is still within the original base path
            $realItemPath = realpath($localItemPath);
            if ($realItemPath === false || strpos($realItemPath, $realLocalPath) !== 0) {
                Log::warning("Skipping file outside allowed directory: {$localItemPath}");
                continue;
            }
            
            $remoteItemPath = rtrim($remotePath, '/') . '/' . $item;

            if (is_dir($localItemPath)) {
                try {
                    $this->fileRepository->setServer($server)->createDirectory($item, $remotePath);
                } catch (\Exception $e) {
                    Log::info("Directory creation skipped for {$remoteItemPath}: " . $e->getMessage());
                }
                $this->uploadDirectoryRecursively($server, $localItemPath, $remoteItemPath);
            } else {
                $content = file_get_contents($localItemPath);
                $this->fileRepository->setServer($server)->putContent($remoteItemPath, $content);
            }
        }
    }

    /**
     * Recursively delete a directory.
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
