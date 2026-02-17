<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Everest\Models\Server;
use Everest\Models\ServerCustomDomain;
use Illuminate\Http\JsonResponse;
use Everest\Jobs\CustomDomains\ProvisionServerCustomDomainsJob;
use Everest\Jobs\CustomDomains\ProvisionCustomDomainRecordJob;
use Everest\Services\CustomDomains\CustomDomainProvisioningService;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Http\Requests\Api\Client\Servers\CustomDomains\GetCustomDomainsRequest;
use Everest\Http\Requests\Api\Client\Servers\CustomDomains\SyncCustomDomainsRequest;
use Everest\Http\Requests\Api\Client\Servers\CustomDomains\StoreCustomDomainRequest;
use Everest\Http\Requests\Api\Client\Servers\CustomDomains\DeleteCustomDomainRequest;

class CustomDomainController extends ClientApiController
{
    public function __construct(private CustomDomainProvisioningService $service)
    {
        parent::__construct();
    }

    public function index(GetCustomDomainsRequest $request, Server $server): JsonResponse
    {
        $records = $server->customDomains()->with('customDomain')->orderByDesc('id')->get()->map(function ($row) {
            return [
                'id' => $row->id,
                'domain_id' => $row->custom_domain_id,
                'domain' => $row->customDomain?->domain,
                'subdomain' => $row->subdomain,
                'full_domain' => $row->full_domain,
                'port' => $row->port,
                'protocol' => $row->protocol,
                'ssl_enabled' => $row->ssl_enabled,
                'ssl_status' => $row->ssl_status,
                'status' => $row->status,
                'last_error' => $row->last_error,
                'last_synced_at' => $row->last_synced_at,
            ];
        })->values();

        return response()->json(['data' => $records]);
    }

    public function store(StoreCustomDomainRequest $request, Server $server): JsonResponse
    {
        $domainId = (int) $request->input('domain_id');
        $subdomain = strtolower((string) $request->input('subdomain'));
        $port = (int) $request->input('port');
        $protocol = (string) $request->input('protocol', 'both');

        $this->service->createFromPayload($server, [[
            'domain_id' => $domainId,
            'subdomain' => $subdomain,
            'port' => $port,
            'protocol' => $protocol,
            'ssl_enabled' => (bool) $request->boolean('ssl_enabled'),
        ]]);

        $mapping = $server->customDomains()
            ->where('custom_domain_id', $domainId)
            ->where('subdomain', $subdomain)
            ->where('port', $port)
            ->where('protocol', $protocol)
            ->latest()
            ->first();

        if ($mapping) {
            ProvisionCustomDomainRecordJob::dispatch($mapping->id);
        } else {
            ProvisionServerCustomDomainsJob::dispatch($server->id);
        }

        return response()->json([], JsonResponse::HTTP_CREATED);
    }

    public function destroy(DeleteCustomDomainRequest $request, Server $server, ServerCustomDomain $customDomain): JsonResponse
    {
        if ($customDomain->server_id !== $server->id) {
            abort(404);
        }

        $this->service->cleanup($customDomain);
        $customDomain->delete();

        return response()->json([], JsonResponse::HTTP_NO_CONTENT);
    }

    public function sync(SyncCustomDomainsRequest $request, Server $server): JsonResponse
    {
        ProvisionServerCustomDomainsJob::dispatch($server->id);

        return response()->json(['message' => 'Custom domain provisioning has been queued.']);
    }
}
