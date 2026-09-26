<?php

namespace Everest\Http\Controllers\Api\Application\Nodes;

use Everest\Models\Node;
use Illuminate\Http\JsonResponse;
use Everest\Services\Servers\ServerPowerStateService;
use Everest\Http\Requests\Api\Application\Servers\GetServersRequest;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;

class NodeServerStatesController extends ApplicationApiController
{
    public function __construct(private ServerPowerStateService $service)
    {
        parent::__construct();
    }

    /**
     * Power state of each server on the node, keyed by UUID. A server Wings
     * didn't report is left out; `reachable: false` means the node didn't
     * answer, so every state on it is unknown.
     */
    public function __invoke(GetServersRequest $request, Node $node): JsonResponse
    {
        $result = $this->service->forNode($node);

        // An object even when empty, so clients can always index it by UUID.
        return new JsonResponse([
            'reachable' => $result['reachable'],
            'states' => (object) $result['states'],
        ]);
    }
}
