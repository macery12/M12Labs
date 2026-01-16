<?php

namespace Everest\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Everest\Models\Server;
use Everest\Services\Mods\CurseForgeService;
use Everest\Services\Servers\ServerCreationService;
use Everest\Repositories\Eloquent\ServerRepository;
use Everest\Repositories\Wings\DaemonServerRepository;
use Everest\Transformers\Api\Client\ServerTransformer;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Exceptions\Service\Mods\ModsServiceException;
use Illuminate\Support\Facades\Log;
use Everest\Models\EggVariable;

class AccountModpacksController extends ClientApiController
{
    /**
     * AccountModpacksController constructor.
     */
    public function __construct(
        private CurseForgeService $curseForgeService,
        private ServerRepository $serverRepository,
        private DaemonServerRepository $daemonServerRepository,
    ) {
        parent::__construct();
    }

    /**
     * Search for modpacks in the CurseForge database (account-level).
     */
    public function search(Request $request): JsonResponse
    {
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
     * Get details of a specific modpack (account-level).
     */
    public function getModpack(int $modpackId): JsonResponse
    {
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
     * Get files for a specific modpack (account-level).
     */
    public function getModpackFiles(Request $request, int $modpackId): JsonResponse
    {
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
     * Get available Minecraft versions (account-level).
     */
    public function getMinecraftVersions(): JsonResponse
    {
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
     * Get available mod loader types (account-level).
     */
    public function getModLoaderTypes(): JsonResponse
    {
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
     * Get current modpack info for a server.
     */
    public function getServerModpackInfo(Request $request, string $serverId): JsonResponse
    {
        try {
            $server = $this->serverRepository->findFirstWhere([
                ['uuid', '=', $serverId],
                ['owner_id', '=', $request->user()->id],
            ]);

            if (!$server) {
                return response()->json([
                    'error' => 'Server not found or you do not have access to it.',
                ], 404);
            }

            // Get PROJECT_ID and VERSION_ID from server environment variables
            $projectId = null;
            $versionId = null;
            $modpackName = null;

            foreach ($server->variables as $variable) {
                if ($variable->env_variable === 'PROJECT_ID') {
                    $projectId = $variable->server_value;
                }
                if ($variable->env_variable === 'VERSION_ID') {
                    $versionId = $variable->server_value;
                }
            }

            // If we have a project ID, try to get the modpack name
            if ($projectId) {
                try {
                    $modpackData = $this->curseForgeService->getModpack((int)$projectId);
                    if (isset($modpackData['data']['name'])) {
                        $modpackName = $modpackData['data']['name'];
                    }
                } catch (\Exception $e) {
                    // Ignore errors fetching modpack name
                    Log::debug('Could not fetch modpack name for project ID: ' . $projectId);
                }
            }

            return response()->json([
                'projectId' => $projectId,
                'versionId' => $versionId,
                'modpackName' => $modpackName,
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting server modpack info: ' . $e->getMessage());
            return response()->json([
                'error' => 'An error occurred while fetching server modpack info.',
            ], 500);
        }
    }

    /**
     * Install a modpack to a server.
     * This updates environment variables and triggers a reinstall.
     */
    public function install(Request $request): JsonResponse
    {
        $request->validate([
            'serverId' => 'required|string',
            'modpackId' => 'required|integer',
            'fileId' => 'nullable|integer',
        ]);

        $serverId = $request->input('serverId');
        $modpackId = $request->input('modpackId');
        $fileId = $request->input('fileId');

        try {
            // Find the server and ensure the user owns it
            $server = $this->serverRepository->findFirstWhere([
                ['uuid', '=', $serverId],
                ['owner_id', '=', $request->user()->id],
            ]);

            if (!$server) {
                return response()->json([
                    'error' => 'Server not found or you do not have access to it.',
                ], 404);
            }

            if (!$server->mods_enabled) {
                return response()->json([
                    'error' => 'Mods are not enabled for this server.',
                ], 403);
            }

            // Get the CurseForge API key from settings
            $apiKey = config('everest.mods.curseforge_api_key', '');
            if (empty($apiKey)) {
                return response()->json([
                    'error' => 'CurseForge API key is not configured.',
                ], 500);
            }

            // Update environment variables
            $projectIdVar = EggVariable::where('egg_id', $server->egg_id)
                ->where('env_variable', 'PROJECT_ID')
                ->first();
            
            $versionIdVar = EggVariable::where('egg_id', $server->egg_id)
                ->where('env_variable', 'VERSION_ID')
                ->first();
            
            $apiKeyVar = EggVariable::where('egg_id', $server->egg_id)
                ->where('env_variable', 'API_KEY')
                ->first();

            if (!$projectIdVar || !$apiKeyVar) {
                return response()->json([
                    'error' => 'This server does not support modpack installation. Missing required environment variables (PROJECT_ID or API_KEY).',
                ], 400);
            }

            // Prepare environment variables
            $environment = [];
            
            // Update PROJECT_ID
            $environment[$projectIdVar->env_variable] = (string)$modpackId;
            
            // Update VERSION_ID if fileId is provided
            if ($fileId && $versionIdVar) {
                $environment[$versionIdVar->env_variable] = (string)$fileId;
            } elseif ($versionIdVar) {
                $environment[$versionIdVar->env_variable] = '';
            }
            
            // Update API_KEY
            $environment[$apiKeyVar->env_variable] = $apiKey;

            // Update all other existing variables to maintain their current values
            foreach ($server->variables as $variable) {
                if (!isset($environment[$variable->env_variable])) {
                    $environment[$variable->env_variable] = $variable->server_value;
                }
            }

            // Update server environment variables in database
            foreach ($server->variables as $variable) {
                if (isset($environment[$variable->env_variable])) {
                    $variable->server_value = $environment[$variable->env_variable];
                    $variable->save();
                }
            }

            // Sync environment to Wings
            $this->daemonServerRepository->setServer($server)->sync();

            // Trigger server reinstall
            $this->daemonServerRepository->setServer($server)->reinstall();

            Log::info('Modpack installation initiated', [
                'server_id' => $server->id,
                'server_uuid' => $server->uuid,
                'modpack_id' => $modpackId,
                'file_id' => $fileId,
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Modpack installation initiated. The server will be reinstalled.',
            ]);
        } catch (\Exception $e) {
            Log::error('Modpack installation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'server_id' => $serverId,
                'modpack_id' => $modpackId,
            ]);

            return response()->json([
                'error' => 'An error occurred while installing the modpack: ' . $e->getMessage(),
            ], 500);
        }
    }
}
