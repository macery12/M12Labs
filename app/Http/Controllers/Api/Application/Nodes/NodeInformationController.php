<?php

namespace Everest\Http\Controllers\Api\Application\Nodes;

use Everest\Models\Node;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Everest\Services\Nodes\WingsDetectionService;
use Everest\Repositories\Wings\DaemonConfigurationRepository;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Http\Requests\Api\Application\Nodes\GetNodeInformationRequest;

class NodeInformationController extends ApplicationApiController
{
    /**
     * NodeInformationController constructor.
     */
    public function __construct(
        private DaemonConfigurationRepository $repository,
        private WingsDetectionService $detectionService
    )
    {
        parent::__construct();
    }

    /**
     * Returns system information from the node.
     *
     * @throws \Everest\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function information(GetNodeInformationRequest $request, Node $node): JsonResponse
    {
        if (!$node->isSupercharged()) {
            $this->detectionService->detect($node);
            $node->refresh();
        }

        $data = $this->repository->setNode($node)->getSystemInformation();

        $isSupercharged = $node->isSupercharged() || !empty($data['supercharged']);

        return new JsonResponse([
            'version' => $data['version'] ?? null,
            'system' => [
                'type' => Str::title($data['os'] ?? 'Unknown'),
                'arch' => $data['architecture'] ?? null,
                'release' => $data['kernel_version'] ?? null,
                'cpus' => $data['cpu_count'] ?? null,
                'supercharged' => $isSupercharged,
            ],
        ]);
    }

    /**
     * Returns system utilization from the node.
     *
     * @throws \Everest\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function utilization(GetNodeInformationRequest $request, Node $node): JsonResponse
    {
        $data = $this->repository->setNode($node)->getSystemUtilization();

        return new JsonResponse([
            'cpu' => $data['cpu'],
            'memory' => [
                'total' => $data['memory_total'],
                'used' => $data['memory_used'],
            ],
            'swap' => [
                'total' => $data['swap_total'],
                'used' => $data['swap_used'],
            ],
            'disk' => [
                'total' => $data['disk_total'],
                'used' => $data['disk_used'],
            ],
        ]);
    }
}
