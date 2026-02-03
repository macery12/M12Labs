<?php

namespace Everest\Http\Controllers\Api\Client\Extensions;

use Everest\Models\Server;
use Illuminate\Http\JsonResponse;
use Everest\Models\ExtensionConfig;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Http\Requests\Api\Client\Extensions\GetServerExtensionsRequest;

class ExtensionsController extends ClientApiController
{
    /**
     * Get all enabled extensions for a server.
     */
    public function index(GetServerExtensionsRequest $request, Server $server): JsonResponse
    {
        $enabledConfigs = ExtensionConfig::getEnabledForServer($server);
        $availableExtensions = config('modules.extensions.available', []);

        $extensions = [];
        foreach ($enabledConfigs as $config) {
            $extensionDef = $availableExtensions[$config->extension_id] ?? null;
            if ($extensionDef) {
                $extensions[] = [
                    'id' => $config->extension_id,
                    'name' => $extensionDef['name'],
                    'description' => $extensionDef['description'],
                    'icon' => $extensionDef['icon'],
                    'settings' => $config->settings ?? [],
                ];
            }
        }

        return new JsonResponse([
            'object' => 'list',
            'data' => $extensions,
        ]);
    }

    /**
     * Check if a specific extension is enabled for a server.
     */
    public function check(GetServerExtensionsRequest $request, Server $server, string $extensionId): JsonResponse
    {
        $config = ExtensionConfig::getByExtensionId($extensionId);

        if (!$config || !$config->isServerEligible($server)) {
            return new JsonResponse([
                'enabled' => false,
            ]);
        }

        return new JsonResponse([
            'enabled' => true,
        ]);
    }
}
