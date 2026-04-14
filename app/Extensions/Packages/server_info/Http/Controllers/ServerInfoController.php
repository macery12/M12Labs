<?php

namespace Everest\Extensions\Packages\server_info\Http\Controllers;

use Everest\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerInfoController
{
    public function index(Request $request, Server $server): JsonResponse
    {
        $user = $request->user();

        return new JsonResponse([
            'object' => 'extension_server_info',
            'attributes' => [
                'name' => $server->name,
                'node' => $server->node->name,
                'egg' => $server->egg->name,
                'serverOwner' => $server->owner_id === $user->id,
                'panel' => [
                    'name' => config('app.name', 'M12Labs'),
                    'version' => config('app.version'),
                ],
            ],
        ]);
    }
}