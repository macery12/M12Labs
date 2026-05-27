<?php

namespace Everest\Http\Controllers\Api\Application\CustomDomains;

use Everest\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Everest\Facades\Activity;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Http\Requests\Api\Application\CustomDomains\GetCustomDomainSettingsRequest;
use Everest\Http\Requests\Api\Application\CustomDomains\UpdateCustomDomainSettingsRequest;

class SettingsController extends ApplicationApiController
{
    public function index(GetCustomDomainSettingsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'cloudflare_token' => (string) config('modules.custom_domains.cloudflare.token', ''),
                'allow_wildcard' => (bool) config('modules.custom_domains.security.allow_wildcard', false),
                'max_wildcards_per_user' => (int) config('modules.custom_domains.security.max_wildcards_per_user', 1),
                'rate_limit_create_per_minute' => (int) config('modules.custom_domains.rate_limits.create_per_minute', 10),
                'rate_limit_sync_per_minute' => (int) config('modules.custom_domains.rate_limits.sync_per_minute', 5),
                'rate_limit_billing_options_per_minute' => (int) config('modules.custom_domains.rate_limits.billing_options_per_minute', 20),
            ],
        ]);
    }

    public function update(UpdateCustomDomainSettingsRequest $request): Response
    {
        if ($request->has('cloudflare_token')) {
            Setting::set('settings::modules:custom_domains:cloudflare:token', (string) $request->input('cloudflare_token', ''));
        }

        Setting::set('settings::modules:custom_domains:security:allow_wildcard', $request->boolean('allow_wildcard', false));
        Setting::set('settings::modules:custom_domains:security:max_wildcards_per_user', (int) $request->input('max_wildcards_per_user', 1));
        Setting::set('settings::modules:custom_domains:rate_limits:create_per_minute', (int) $request->input('rate_limit_create_per_minute', 10));
        Setting::set('settings::modules:custom_domains:rate_limits:sync_per_minute', (int) $request->input('rate_limit_sync_per_minute', 5));
        Setting::set('settings::modules:custom_domains:rate_limits:billing_options_per_minute', (int) $request->input('rate_limit_billing_options_per_minute', 20));

        Activity::event('admin:custom-domains:update-settings')
            ->description('Custom domain settings were updated')
            ->log();

        return $this->returnNoContent();
    }
}
