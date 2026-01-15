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
}
