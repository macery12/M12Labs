<?php

namespace Everest\Http\Controllers\Api\Client;

use Everest\Models\User;
use Everest\Models\Server;
use Illuminate\Http\Request;
use Everest\Models\EggVariable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Everest\Models\Setting;
use Everest\Services\Mods\CurseForgeService;
use Everest\Repositories\Eloquent\ServerRepository;
use Everest\Services\Servers\ReinstallServerService;
use Everest\Repositories\Wings\DaemonServerRepository;
use Everest\Services\Servers\StartupModificationService;
use Everest\Exceptions\Service\Mods\ModsServiceException;

class AccountModpacksController extends ClientApiController
{
    /**
     * AccountModpacksController constructor.
     */
    public function __construct(
        private CurseForgeService $curseForgeService,
        private ServerRepository $serverRepository,
        private DaemonServerRepository $daemonServerRepository,
        private StartupModificationService $startupModificationService,
        private ReinstallServerService $reinstallServerService,
    ) {
        parent::__construct();
    }

    /**
     * Search for modpacks in the CurseForge database (account-level).
     */
    public function search(Request $request): JsonResponse
    {
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
     * Get servers that are compatible with modpack installation.
     * Only returns servers with CurseForge-compatible eggs (having PROJECT_ID and API_KEY variables).
     */
    public function getCompatibleServers(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            // Get all servers owned by the user (only the columns we need).
            $servers = Server::where('owner_id', $user->id)->get(['uuid', 'name', 'egg_id']);

            // Resolve which eggs are CurseForge-compatible in a single query instead of
            // two existence checks per server. An egg is compatible when it exposes BOTH
            // the PROJECT_ID and API_KEY variables.
            $compatibleEggIds = EggVariable::query()
                ->whereIn('egg_id', $servers->pluck('egg_id')->unique()->filter()->all())
                ->whereIn('env_variable', ['PROJECT_ID', 'API_KEY'])
                ->groupBy('egg_id')
                ->havingRaw('COUNT(DISTINCT env_variable) = 2')
                ->pluck('egg_id')
                ->flip();

            $compatibleServers = $servers
                ->filter(fn ($server) => $compatibleEggIds->has($server->egg_id))
                ->map(fn ($server) => [
                    'uuid' => $server->uuid,
                    'name' => $server->name,
                    'eggId' => $server->egg_id,
                ])
                ->values()
                ->all();

            return response()->json([
                'servers' => $compatibleServers,
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting compatible servers: ' . $e->getMessage());

            return response()->json([
                'error' => 'An error occurred while fetching compatible servers.',
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
                    $modpackData = $this->curseForgeService->getModpack((int) $projectId);
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

            // Get the CurseForge API key from settings
            $apiKey = Setting::get('settings::modules:mods:curseforge_api_key', config('modules.mods.curseforge_api_key', ''));
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
            $environment['PROJECT_ID'] = (string) $modpackId;

            // Update VERSION_ID if fileId is provided
            if ($fileId && $versionIdVar) {
                $environment['VERSION_ID'] = (string) $fileId;
            } elseif ($versionIdVar) {
                $environment['VERSION_ID'] = '';
            }

            // Update API_KEY
            $environment['API_KEY'] = $apiKey;

            // Get all current server variables and add them to environment
            // to maintain their current values
            foreach ($server->variables as $variable) {
                if (!isset($environment[$variable->env_variable])) {
                    $environment[$variable->env_variable] = $variable->server_value;
                }
            }

            // Use StartupModificationService to properly update environment variables
            $server = $this->startupModificationService
                ->setUserLevel(User::USER_LEVEL_USER)
                ->handle($server, [
                    'environment' => $environment,
                ]);

            // Trigger server reinstall using the proper service
            // This sets the server status to installing and triggers the daemon reinstall
            $this->reinstallServerService->handle($server);

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
