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
            ],
        ]);
    }

    public function update(UpdateCustomDomainSettingsRequest $request): Response
    {
        Setting::set('settings::modules:custom_domains:cloudflare:token', (string) $request->input('cloudflare_token', ''));

        Activity::event('admin:custom-domains:update-settings')
            ->description('Custom domain settings were updated')
            ->log();

        return $this->returnNoContent();
    }
}
