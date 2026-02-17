<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Everest\Models\CustomDomain;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Http\Requests\Api\Application\Billing\CustomDomains\GetCustomDomainsRequest;
use Everest\Http\Requests\Api\Application\Billing\CustomDomains\StoreCustomDomainRequest;
use Everest\Http\Requests\Api\Application\Billing\CustomDomains\DeleteCustomDomainRequest;
use Everest\Http\Requests\Api\Application\Billing\CustomDomains\UpdateCustomDomainRequest;

class CustomDomainController extends ApplicationApiController
{
    public function index(GetCustomDomainsRequest $request): JsonResponse
    {
        $domains = CustomDomain::query()->orderBy('domain')->get();

        return response()->json(['data' => $domains]);
    }

    public function store(StoreCustomDomainRequest $request): JsonResponse
    {
        $domain = CustomDomain::query()->create([
            'domain' => strtolower($request->input('domain')),
            'cloudflare_zone_id' => $request->input('cloudflare_zone_id'),
            'wildcard_enabled' => $request->boolean('wildcard_enabled', false),
            'enabled' => $request->boolean('enabled', true),
        ]);

        return response()->json(['data' => $domain], Response::HTTP_CREATED);
    }

    public function update(UpdateCustomDomainRequest $request, CustomDomain $customDomain): JsonResponse
    {
        $customDomain->update([
            'domain' => strtolower($request->input('domain', $customDomain->domain)),
            'cloudflare_zone_id' => $request->input('cloudflare_zone_id', $customDomain->cloudflare_zone_id),
            'wildcard_enabled' => $request->boolean('wildcard_enabled', $customDomain->wildcard_enabled),
            'enabled' => $request->boolean('enabled', $customDomain->enabled),
        ]);

        return response()->json(['data' => $customDomain->fresh()]);
    }

    public function destroy(DeleteCustomDomainRequest $request, CustomDomain $customDomain): Response
    {
        $customDomain->delete();

        return $this->returnNoContent();
    }
}
