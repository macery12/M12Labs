<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Everest\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Everest\Services\Mods\CurseForgeService;
use Everest\Repositories\Wings\DaemonFileRepository;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Http\Requests\Api\Client\Servers\Mods\SearchModsRequest;
use Everest\Http\Requests\Api\Client\Servers\Mods\GetModRequest;
use Everest\Http\Requests\Api\Client\Servers\Mods\GetModFilesRequest;
use Everest\Http\Requests\Api\Client\Servers\Mods\DownloadModRequest;
use Everest\Http\Requests\Api\Client\Servers\Mods\GetMinecraftVersionsRequest;
use Everest\Exceptions\Service\Mods\ModsServiceException;

class ModsController extends ClientApiController
{
    /**
     * ModsController constructor.
     */
    public function __construct(
        private CurseForgeService $curseForgeService,
        private DaemonFileRepository $fileRepository
    ) {
        parent::__construct();
    }

    /**
     * Search for mods in the CurseForge database.
     *
     * @throws ModsServiceException
     */
    public function search(SearchModsRequest $request, Server $server): JsonResponse
    {
        if (!$server->mods_enabled) {
            return response()->json([
                'error' => 'Mods module is not enabled for this server.',
            ], 403);
        }

        $params = array_filter([
            'searchFilter' => $request->input('searchFilter'),
            'sortField' => $request->input('sortField'),
            'sortOrder' => $request->input('sortOrder'),
            'gameVersion' => $request->input('gameVersion'),
            'modLoaderType' => $request->input('modLoaderType'),
            'pageSize' => $request->input('pageSize', 20),
            'index' => $request->input('index', 0),
        ], function ($value) {
            return $value !== null;
        });

        try {
            $result = $this->curseForgeService->searchMods($params);
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
    public function getMod(GetModRequest $request, Server $server, int $modId): JsonResponse
    {
        if (!$server->mods_enabled) {
            return response()->json([
                'error' => 'Mods module is not enabled for this server.',
            ], 403);
        }

        try {
            $result = $this->curseForgeService->getMod($modId);
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
    public function getModFiles(GetModFilesRequest $request, Server $server, int $modId): JsonResponse
    {
        if (!$server->mods_enabled) {
            return response()->json([
                'error' => 'Mods module is not enabled for this server.',
            ], 403);
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
            $result = $this->curseForgeService->getModFiles($modId, $params);
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
    public function downloadMod(DownloadModRequest $request, Server $server, int $modId, int $fileId): JsonResponse
    {
        if (!$server->mods_enabled) {
            return response()->json([
                'error' => 'Mods module is not enabled for this server.',
            ], 403);
        }

        try {
            // Get mod file details
            $modFile = $this->curseForgeService->getModFile($modId, $fileId);
            
            if (!isset($modFile['data'])) {
                throw new ModsServiceException('Failed to retrieve mod file details.');
            }

            $fileData = $modFile['data'];
            $fileName = $fileData['fileName'] ?? 'mod.jar';

            // Get download URL
            $downloadUrl = $this->curseForgeService->getModFileDownloadUrl($modId, $fileId);

            // Create /mods folder if it doesn't exist
            try {
                $this->fileRepository->setServer($server)->createDirectory('mods', '/');
            } catch (\Exception $e) {
                // Folder might already exist, that's fine
                Log::info('Mods folder creation skipped: ' . $e->getMessage());
            }

            // Download the mod file
            $response = Http::timeout(300)->get($downloadUrl);
            
            if (!$response->successful()) {
                throw new ModsServiceException('Failed to download mod file from CurseForge.');
            }

            $modContent = $response->body();

            // Upload to server's /mods folder
            $this->fileRepository->setServer($server)->putContent("/mods/{$fileName}", $modContent);

            return response()->json([
                'success' => true,
                'message' => 'Mod downloaded and uploaded successfully.',
                'file' => [
                    'name' => $fileName,
                    'path' => "/mods/{$fileName}",
                ],
            ]);
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
        if (!$server->mods_enabled) {
            return response()->json([
                'error' => 'Mods module is not enabled for this server.',
            ], 403);
        }

        try {
            $result = $this->curseForgeService->getMinecraftVersions();
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
        if (!$server->mods_enabled) {
            return response()->json([
                'error' => 'Mods module is not enabled for this server.',
            ], 403);
        }

        try {
            $result = $this->curseForgeService->getModLoaderTypes();
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
        if (!$server->mods_enabled) {
            return response()->json([
                'error' => 'Mods module is not enabled for this server.',
            ], 403);
        }

        $params = array_filter([
            'searchFilter' => $request->input('searchFilter'),
            'sortField' => $request->input('sortField'),
            'sortOrder' => $request->input('sortOrder'),
            'gameVersion' => $request->input('gameVersion'),
            'modLoaderType' => $request->input('modLoaderType'),
            'pageSize' => $request->input('pageSize', 20),
            'index' => $request->input('index', 0),
        ], function ($value) {
            return $value !== null;
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
        if (!$server->mods_enabled) {
            return response()->json([
                'error' => 'Mods module is not enabled for this server.',
            ], 403);
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
        if (!$server->mods_enabled) {
            return response()->json([
                'error' => 'Mods module is not enabled for this server.',
            ], 403);
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
        if (!$server->mods_enabled) {
            return response()->json([
                'error' => 'Mods module is not enabled for this server.',
            ], 403);
        }

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

            // Download the modpack file with size validation
            $response = Http::timeout(300)->get($downloadUrl);
            
            if (!$response->successful()) {
                throw new ModsServiceException('Failed to download modpack file from CurseForge.');
            }

            $modpackContent = $response->body();

            // Validate downloaded content size
            if (strlen($modpackContent) > $maxFileSize) {
                throw new ModsServiceException('Downloaded modpack exceeds maximum allowed size.');
            }

            // Create temporary directory for extraction in a secure location
            $baseTempDir = storage_path('app/temp');
            if (!is_dir($baseTempDir)) {
                mkdir($baseTempDir, 0755, true);
            }
            
            $tempDir = $baseTempDir . '/modpack_' . uniqid('', true);
            mkdir($tempDir, 0755, true);
            $zipPath = $tempDir . '/modpack.zip';

            // Save zip file temporarily
            file_put_contents($zipPath, $modpackContent);

            // Extract the zip file
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) === true) {
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

                    // Download the mod file (can be parallelized, not an API metadata call)
                    $modResponse = Http::timeout(120)->get($modDownloadUrl);
                    
                    if ($modResponse->successful()) {
                        // Upload to server's /mods folder
                        $this->fileRepository->setServer($server)->putContent("/mods/{$modFileName}", $modResponse->body());
                        $downloadedMods[] = $modFileName;
                    } else {
                        $failedMods[] = ['projectId' => $projectId, 'fileId' => $modFileId];
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
     * Recursively upload a directory to the server.
     */
    private function uploadDirectoryRecursively(Server $server, string $localPath, string $remotePath): void
    {
        $items = scandir($localPath);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $localItemPath = $localPath . '/' . $item;
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
