<?php

namespace Everest\Http\Controllers\Api\Application;

use Everest\Models\Setting;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Everest\Models\MarketplaceInstallLog;
use Everest\Services\Email\EmailRedactor;
use Everest\Services\Mods\ModrinthService;
use Everest\Services\Mods\CurseForgeService;
use Everest\Http\Requests\Api\Application\Mods\GetModsAnalyticsRequest;
use Everest\Http\Requests\Api\Application\Mods\UpdateModsSettingsRequest;
use Everest\Http\Requests\Api\Application\Mods\DeleteCurseForgeKeyRequest;

class PluginsController extends ApplicationApiController
{
    public function __construct(
        private CurseForgeService $curseForgeService,
        private ModrinthService $modrinthService
    ) {
        parent::__construct();
    }

    public function update(UpdateModsSettingsRequest $request): Response
    {
        foreach ($request->normalize() as $key => $value) {
            if ($key == 'curseforge_api_key' && is_bool($value)) {
                continue;
            }

            Setting::set('settings::modules:mods:' . $key, $value);
        }

        \Artisan::call('config:clear');

        $activitySettings = EmailRedactor::redactSensitivePayload(
            $request->all(),
            ['api_key', 'token', 'secret', 'password', 'authorization', 'key']
        );

        Activity::event('admin:plugins:update')
            ->property('settings', $activitySettings)
            ->description('Plugins module settings were updated')
            ->log();

        return $this->returnNoContent();
    }

    public function analytics(GetModsAnalyticsRequest $request): JsonResponse
    {
        $curseForgeRateLimit = $this->curseForgeService->getRateLimitUsage();
        $modrinthRateLimit = $this->modrinthService->getRateLimitUsage();

        $totalInstalls = MarketplaceInstallLog::where('status', MarketplaceInstallLog::STATUS_SUCCESS)->count();
        $totalFailures = MarketplaceInstallLog::where('status', MarketplaceInstallLog::STATUS_FAILED)->count();
        $totalBandwidth = MarketplaceInstallLog::where('status', MarketplaceInstallLog::STATUS_SUCCESS)->sum('file_size_bytes');
        $bandwidth24h = MarketplaceInstallLog::where('status', MarketplaceInstallLog::STATUS_SUCCESS)
            ->where('created_at', '>=', now()->subHours(24))
            ->sum('file_size_bytes');

        $byProvider = MarketplaceInstallLog::where('status', MarketplaceInstallLog::STATUS_SUCCESS)
            ->select('provider', DB::raw('count(*) as count'))
            ->groupBy('provider')
            ->pluck('count', 'provider')
            ->toArray();

        $last24h = MarketplaceInstallLog::where('status', MarketplaceInstallLog::STATUS_SUCCESS)
            ->where('created_at', '>=', now()->subHours(24))
            ->select(DB::raw('DATE_FORMAT(created_at, "%Y-%m-%dT%H:00:00Z") as timestamp'), DB::raw('count(*) as installs'))
            ->groupBy('timestamp')
            ->orderBy('timestamp')
            ->get()
            ->toArray();

        $last7d = MarketplaceInstallLog::where('status', MarketplaceInstallLog::STATUS_SUCCESS)
            ->where('created_at', '>=', now()->subDays(7))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as installs'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        return response()->json([
            'totals' => [
                'installs' => $totalInstalls,
                'by_provider' => [
                    'modrinth' => $byProvider['modrinth'] ?? 0,
                    'curseforge' => $byProvider['curseforge'] ?? 0,
                    'spigot' => $byProvider['spigot'] ?? 0,
                ],
                'failures' => $totalFailures,
                'retries' => 0,
                'bandwidth_bytes' => (int) $totalBandwidth,
                'bandwidth_bytes_24h' => (int) $bandwidth24h,
            ],
            'trends' => [
                'last_24h' => $last24h,
                'last_7d' => $last7d,
            ],
            'provider_health' => [
                'modrinth' => [
                    'enabled' => true,
                    'rate_limit' => $modrinthRateLimit,
                    'denied_by_policy' => 0,
                ],
                'curseforge' => [
                    'enabled' => (bool) Setting::get('settings::modules:mods:curseforge_api_key'),
                    'rate_limit' => $curseForgeRateLimit,
                    'denied_by_policy' => 0,
                ],
                'spigot' => [
                    'enabled' => true,
                    'rate_limit' => null,
                    'denied_by_policy' => 0,
                ],
            ],
        ]);
    }

    public function resetKey(DeleteCurseForgeKeyRequest $request): Response
    {
        Setting::forget('settings::modules:mods:curseforge_api_key');

        \Artisan::call('config:clear');

        Activity::event('admin:plugins:reset-key')
            ->description('CurseForge API key for plugins was reset')
            ->log();

        return $this->returnNoContent();
    }
}
