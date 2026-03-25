<?php

namespace Everest\Http\Controllers\Api\Application\Nodes;

use Everest\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Everest\Services\Nodes\WingsDetectionService;
use Everest\Repositories\Wings\DaemonWingsRsRepository;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;

class NodeWingsRsController extends ApplicationApiController
{
    public function __construct(
        private WingsDetectionService $detectionService,
        private DaemonWingsRsRepository $wingsRsRepository
    ) {
        parent::__construct();
    }

    /**
     * POST /api/application/nodes/{node}/detect — Detect Wings-RS and update node type.
     */
    public function detect(Request $request, Node $node): JsonResponse
    {
        $isSupercharged = $this->detectionService->detect($node);
        $node->refresh();

        return new JsonResponse([
            'detected' => $isSupercharged,
            'supercharged' => $isSupercharged,
            'wings_type' => $node->wings_type,
            'wings_version' => $node->wings_version,
            'detected_at' => $node->wings_detected_at?->toIso8601String(),
        ]);
    }

    /**
     * GET /api/application/nodes/{node}/overview — Wings-RS system overview.
     */
    public function overview(Request $request, Node $node): JsonResponse
    {
        if (!$node->isSupercharged()) {
            return new JsonResponse(['error' => 'This node is not running Wings-RS.'], 400);
        }

        $data = $this->wingsRsRepository->setNode($node)->getSystemOverview();

        return new JsonResponse($data);
    }

    /**
     * GET /api/application/nodes/{node}/stats — Wings-RS real-time stats.
     */
    public function stats(Request $request, Node $node): JsonResponse
    {
        if (!$node->isSupercharged()) {
            return new JsonResponse(['error' => 'This node is not running Wings-RS.'], 400);
        }

        $data = $this->wingsRsRepository->setNode($node)->getSystemStats();

        return new JsonResponse($data);
    }

    /**
     * GET /api/application/nodes/{node}/logs — List Wings-RS log files.
     */
    public function logs(Request $request, Node $node): JsonResponse
    {
        if (!$node->isSupercharged()) {
            return new JsonResponse(['error' => 'This node is not running Wings-RS.'], 400);
        }

        $data = $this->wingsRsRepository->setNode($node)->getSystemLogs();

        return new JsonResponse($data);
    }

    /**
     * GET /api/application/nodes/{node}/logs/{file} — Read specific log file.
     */
    public function logContents(Request $request, Node $node, string $file): JsonResponse
    {
        if (!$node->isSupercharged()) {
            return new JsonResponse(['error' => 'This node is not running Wings-RS.'], 400);
        }

        $lines = (int) $request->query('lines', 200);
        $lines = max(1, min($lines, 5000));

        $content = $this->wingsRsRepository->setNode($node)->getSystemLogContents($file, $lines);

        return new JsonResponse([
            'file' => $file,
            'content' => $content,
        ]);
    }

    /**
     * POST /api/application/nodes/{node}/upgrade — Trigger Wings-RS self-upgrade.
     */
    public function upgrade(Request $request, Node $node): JsonResponse
    {
        if (!$node->isSupercharged()) {
            return new JsonResponse(['error' => 'This node is not running Wings-RS.'], 400);
        }

        $request->validate([
            'url' => 'required|url',
            'sha256' => 'required|string|size:64',
            'restart_command' => 'required|string',
            'restart_command_args' => 'array',
            'restart_command_args.*' => 'string',
            'headers' => 'array',
        ]);

        $this->wingsRsRepository->setNode($node)->upgradeSystem(
            $request->input('url'),
            $request->input('headers', []),
            $request->input('sha256'),
            $request->input('restart_command'),
            $request->input('restart_command_args', [])
        );

        return new JsonResponse(['success' => true], 202);
    }
}
