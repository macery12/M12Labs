<?php

namespace Everest\Http\Controllers\Api\Application;

use Everest\Models\Setting;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Services\Mods\CurseForgeService;
use Everest\Http\Requests\Api\Application\Mods\UpdateModsSettingsRequest;
use Everest\Http\Requests\Api\Application\Mods\DeleteCurseForgeKeyRequest;
use Everest\Http\Requests\Api\Application\Mods\GetModsAnalyticsRequest;

class ModsController extends ApplicationApiController
{
    /**
     * ModsController constructor.
     */
    public function __construct(private CurseForgeService $curseForgeService)
    {
        parent::__construct();
    }

    /**
     * Update the mods settings for the Panel.
     *
     * @throws \Throwable
     */
    public function update(UpdateModsSettingsRequest $request): Response
    {
        foreach ($request->normalize() as $key => $value) {
            if ($key == 'curseforge_api_key' && is_bool($value)) {
                continue;
            }

            Setting::set('settings::modules:mods:' . $key, $value);
            
            // Update the runtime config so the new value is available immediately
            config(['modules.mods.' . $key => $value]);
        }
        
        // Clear config cache to ensure changes persist across requests
        if (function_exists('config_clear')) {
            config_clear();
        }
        \Illuminate\Support\Facades\Artisan::call('config:clear');

        Activity::event('admin:mods:update')
            ->property('settings', $request->all())
            ->description('Mods module settings were updated')
            ->log();

        return $this->returnNoContent();
    }

    /**
     * Get mods analytics and rate limit usage.
     */
    public function analytics(GetModsAnalyticsRequest $request): JsonResponse
    {
        $rateLimitUsage = $this->curseForgeService->getRateLimitUsage();

        return response()->json([
            'rate_limit' => $rateLimitUsage,
        ]);
    }

    /**
     * Delete the CurseForge API key saved to the Panel.
     */
    public function resetKey(DeleteCurseForgeKeyRequest $request): Response
    {
        Setting::forget('settings::modules:mods:curseforge_api_key');
        
        // Clear the runtime config so it's not available
        config(['modules.mods.curseforge_api_key' => '']);
        
        // Clear config cache to ensure changes persist across requests
        if (function_exists('config_clear')) {
            config_clear();
        }
        \Illuminate\Support\Facades\Artisan::call('config:clear');

        Activity::event('admin:mods:reset-key')
            ->description('CurseForge API key for mods was reset')
            ->log();

        return $this->returnNoContent();
    }
}
