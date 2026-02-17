<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Services\CustomDomains\CustomDomainProvisioningService;
use Illuminate\Http\JsonResponse;

class CustomDomainOptionsController extends ClientApiController
{
    public function __construct(private CustomDomainProvisioningService $service)
    {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        $domains = collect($this->service->getAvailableDomains())->map(function ($domain) {
            return [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'wildcard_enabled' => $domain->wildcard_enabled,
            ];
        })->values();

        return response()->json(['data' => $domains]);
    }
}
