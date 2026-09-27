<?php

namespace Everest\Http\Controllers\Api\Application;

use Everest\Models\Server;
use Everest\Models\Setting;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Everest\Models\DownloadQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Everest\Models\MarketplaceInstallLog;
use Everest\Services\Email\EmailRedactor;
use Everest\Services\Mods\ModrinthService;
use Everest\Http\Requests\Api\Application\Mods\GetModsAnalyticsRequest;
use Everest\Http\Requests\Api\Application\Mods\UpdateModsSettingsRequest;

class PluginsController extends ApplicationApiController
{
    public function __construct(
        private ModrinthService $modrinthService,
    ) {
        parent::__construct();
    }

    public function update(UpdateModsSettingsRequest $request): Response
    {
        foreach ($request->normalize() as $key => $value) {
            // Never overwrite the stored CurseForge API key with an empty value —
            // the admin form omits/blanks it unless they are deliberately changing it.
            if ($key === 'curseforge_api_key' && ($value === null || $value === '')) {
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

    /**
     * The most recent failed installs behind the overview's Failures count,
     * each with the reason the download queue recorded for it when there is
     * one. The install log only stores that an install failed; the queue row
     * for the same server and project carries the error.
     */
    public function failures(GetModsAnalyticsRequest $request): JsonResponse
    {
        $logs = MarketplaceInstallLog::where('status', MarketplaceInstallLog::STATUS_FAILED)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $servers = Server::query()
            ->whereIn('id', $logs->pluck('server_id')->filter()->unique())
            ->get(['id', 'uuid', 'name'])
            ->keyBy('id');

        $data = $logs->map(function (MarketplaceInstallLog $log) use ($servers) {
            $queued = $log->server_id === null ? null : DownloadQueue::query()
                ->where('server_id', $log->server_id)
                ->where('project_id', $log->project_id)
                ->where('status', DownloadQueue::STATUS_FAILED)
                ->where('created_at', '<=', $log->created_at)
                ->orderByDesc('created_at')
                ->first(['file_name', 'error_message']);
            $server = $log->server_id === null ? null : $servers->get($log->server_id);

            return [
                'id' => $log->id,
                'provider' => $log->provider,
                'type' => $log->type,
                'project_id' => $log->project_id,
                'file_name' => $queued?->file_name,
                'error' => $queued?->error_message ? mb_strimwidth($queued->error_message, 0, 300, '…') : null,
                'server' => $server ? ['id' => $server->id, 'uuid' => $server->uuid, 'name' => $server->name] : null,
                'created_at' => $log->created_at->toIso8601String(),
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    public function analytics(GetModsAnalyticsRequest $request): JsonResponse
    {
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

        $queuedNow      = DownloadQueue::where('status', DownloadQueue::STATUS_PENDING)->count();
        $downloadingNow = DownloadQueue::where('status', DownloadQueue::STATUS_DOWNLOADING)->count();
        $queueFailed    = DownloadQueue::where('status', DownloadQueue::STATUS_FAILED)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return response()->json([
            'totals' => [
                'installs' => $totalInstalls,
                'by_provider' => [
                    'modrinth' => $byProvider['modrinth'] ?? 0,
                    'spigot' => $byProvider['spigot'] ?? 0,
                    'curseforge' => $byProvider['curseforge'] ?? 0,
                ],
                'failures' => $totalFailures,
                'retries' => 0,
                'bandwidth_bytes' => (int) $totalBandwidth,
                'bandwidth_bytes_24h' => (int) $bandwidth24h,
            ],
            'queue' => [
                'pending'     => $queuedNow,
                'downloading' => $downloadingNow,
                'failed_24h'  => $queueFailed,
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
                'spigot' => [
                    'enabled' => true,
                    'rate_limit' => null,
                    'denied_by_policy' => 0,
                ],
                'curseforge' => [
                    'enabled' => (bool) Setting::get('settings::modules:mods:curseforge_enabled', config('modules.mods.curseforge_enabled', false)),
                    'rate_limit' => null,
                    'denied_by_policy' => 0,
                ],
            ],
        ]);
    }
}
