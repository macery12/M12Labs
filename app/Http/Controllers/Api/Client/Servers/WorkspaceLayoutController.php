<?php

namespace Everest\Http\Controllers\Api\Client\Servers;

use Everest\Models\Server;
use Illuminate\Http\JsonResponse;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Http\Requests\Api\Client\Servers\ConsoleWorkspaceAccessRequest;
use Everest\Http\Requests\Api\Client\Servers\SaveConsoleWorkspaceLayoutRequest;
use Everest\Models\ServerWorkspaceLayout;
use Everest\Services\Servers\ConsoleWorkspaceLayout;

class WorkspaceLayoutController extends ClientApiController
{
    public function __construct(private ConsoleWorkspaceLayout $layoutService)
    {
        parent::__construct();
    }

    public function show(ConsoleWorkspaceAccessRequest $request, Server $server): JsonResponse
    {
        $layout = $this->getLayout($server);

        return response()->json($layout ?? $this->layoutService->default());
    }

    public function update(SaveConsoleWorkspaceLayoutRequest $request, Server $server): JsonResponse
    {
        $payload = $this->layoutService->normalize($request->validated());

        $record = ServerWorkspaceLayout::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'server_uuid' => $server->uuid,
                'layout_key' => ConsoleWorkspaceLayout::LAYOUT_KEY,
            ],
            ['layout_json' => $payload],
        );

        return response()->json($record->layout_json);
    }

    public function reset(ConsoleWorkspaceAccessRequest $request, Server $server): JsonResponse
    {
        ServerWorkspaceLayout::query()
            ->where('user_id', $request->user()->id)
            ->where('server_uuid', $server->uuid)
            ->where('layout_key', ConsoleWorkspaceLayout::LAYOUT_KEY)
            ->delete();

        return response()->json($this->layoutService->default());
    }

    private function getLayout(Server $server): ?array
    {
        $record = ServerWorkspaceLayout::query()
            ->where('user_id', $this->request->user()->id)
            ->where('server_uuid', $server->uuid)
            ->where('layout_key', ConsoleWorkspaceLayout::LAYOUT_KEY)
            ->first();

        return $record?->layout_json;
    }
}
