<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Everest\Models\Server;
use Everest\Models\Setting;
use Illuminate\Http\JsonResponse;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Services\Plugins\ProviderAccessService;

class PluginProviderController extends ClientApiController
{
    public function __construct(private ProviderAccessService $providerAccessService)
    {
        parent::__construct();
    }

    public function index(Server $server): JsonResponse
    {
        $modsEnabled = $server->mods_enabled
            && (bool) Setting::get('settings::modules:mods:enabled', config('modules.mods.enabled', false));

        if (!$modsEnabled) {
            return response()->json([
                'mods' => [],
                'modpacks' => [],
                'plugins' => [],
                'installed' => true,
            ]);
        }

        $providers = $this->providerAccessService->getAllowedProvidersForServer($server->egg_id, $server->nest_id);

        return response()->json(array_merge($providers, ['installed' => true]));
    }
}
