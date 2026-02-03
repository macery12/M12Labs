<?php

namespace Everest\Http\Controllers\Api\Application\Extensions;

use Everest\Models\Egg;
use Everest\Models\Nest;
use Everest\Models\Setting;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Models\ExtensionConfig;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Http\Requests\Api\Application\Extensions\GetExtensionsRequest;
use Everest\Http\Requests\Api\Application\Extensions\UpdateExtensionRequest;
use Everest\Http\Requests\Api\Application\Extensions\UpdateExtensionSettingsRequest;

class ExtensionsController extends ApplicationApiController
{
    /**
     * ExtensionsController constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get all available extensions with their configurations.
     */
    public function index(GetExtensionsRequest $request): JsonResponse
    {
        $availableExtensions = config('modules.extensions.available', []);
        $extensions = [];

        foreach ($availableExtensions as $extensionId => $extensionConfig) {
            $dbConfig = ExtensionConfig::getByExtensionId($extensionId);

            $extensions[] = [
                'id' => $extensionId,
                'name' => $extensionConfig['name'],
                'description' => $extensionConfig['description'],
                'version' => $extensionConfig['version'],
                'author' => $extensionConfig['author'],
                'icon' => $extensionConfig['icon'],
                'enabled' => $dbConfig ? $dbConfig->enabled : false,
                'allowed_nests' => $dbConfig ? $dbConfig->allowed_nests : [],
                'allowed_eggs' => $dbConfig ? $dbConfig->allowed_eggs : [],
                'settings' => $dbConfig ? $dbConfig->settings : [],
            ];
        }

        return new JsonResponse([
            'object' => 'list',
            'data' => $extensions,
        ]);
    }

    /**
     * Get a single extension configuration.
     */
    public function view(GetExtensionsRequest $request, string $extensionId): JsonResponse
    {
        $availableExtensions = config('modules.extensions.available', []);

        if (!isset($availableExtensions[$extensionId])) {
            return new JsonResponse(['error' => 'Extension not found'], 404);
        }

        $extensionConfig = $availableExtensions[$extensionId];
        $dbConfig = ExtensionConfig::getByExtensionId($extensionId);

        return new JsonResponse([
            'object' => 'extension',
            'attributes' => [
                'id' => $extensionId,
                'name' => $extensionConfig['name'],
                'description' => $extensionConfig['description'],
                'version' => $extensionConfig['version'],
                'author' => $extensionConfig['author'],
                'icon' => $extensionConfig['icon'],
                'enabled' => $dbConfig ? $dbConfig->enabled : false,
                'allowed_nests' => $dbConfig ? $dbConfig->allowed_nests : [],
                'allowed_eggs' => $dbConfig ? $dbConfig->allowed_eggs : [],
                'settings' => $dbConfig ? $dbConfig->settings : [],
            ],
        ]);
    }

    /**
     * Update an extension configuration.
     */
    public function update(UpdateExtensionRequest $request, string $extensionId): JsonResponse
    {
        $availableExtensions = config('modules.extensions.available', []);

        if (!isset($availableExtensions[$extensionId])) {
            return new JsonResponse(['error' => 'Extension not found'], 404);
        }

        $config = ExtensionConfig::updateOrCreateConfig($extensionId, [
            'enabled' => $request->input('enabled', false),
            'allowed_nests' => $request->input('allowed_nests', []),
            'allowed_eggs' => $request->input('allowed_eggs', []),
            'settings' => $request->input('settings', []),
        ]);

        Activity::event('admin:extensions:update')
            ->property('extension_id', $extensionId)
            ->property('enabled', $config->enabled)
            ->log();

        return new JsonResponse([
            'object' => 'extension',
            'attributes' => [
                'id' => $extensionId,
                'name' => $availableExtensions[$extensionId]['name'],
                'description' => $availableExtensions[$extensionId]['description'],
                'version' => $availableExtensions[$extensionId]['version'],
                'author' => $availableExtensions[$extensionId]['author'],
                'icon' => $availableExtensions[$extensionId]['icon'],
                'enabled' => $config->enabled,
                'allowed_nests' => $config->allowed_nests,
                'allowed_eggs' => $config->allowed_eggs,
                'settings' => $config->settings,
            ],
        ]);
    }

    /**
     * Toggle an extension's enabled state.
     */
    public function toggle(UpdateExtensionRequest $request, string $extensionId): JsonResponse
    {
        $availableExtensions = config('modules.extensions.available', []);

        if (!isset($availableExtensions[$extensionId])) {
            return new JsonResponse(['error' => 'Extension not found'], 404);
        }

        $dbConfig = ExtensionConfig::getByExtensionId($extensionId);
        $newEnabled = $dbConfig ? !$dbConfig->enabled : true;

        $config = ExtensionConfig::updateOrCreateConfig($extensionId, [
            'enabled' => $newEnabled,
        ]);

        Activity::event('admin:extensions:toggle')
            ->property('extension_id', $extensionId)
            ->property('enabled', $config->enabled)
            ->log();

        return new JsonResponse([
            'object' => 'extension',
            'attributes' => [
                'id' => $extensionId,
                'enabled' => $config->enabled,
            ],
        ]);
    }

    /**
     * Update the extensions module settings.
     */
    public function settings(UpdateExtensionSettingsRequest $request): Response
    {
        Setting::set('settings::modules:extensions:' . $request->input('key'), $request->input('value'));

        Activity::event('admin:extensions:settings')
            ->property('key', $request->input('key'))
            ->property('value', $request->input('value'))
            ->log();

        return $this->returnNoContent();
    }

    /**
     * Get available nests and eggs for extension configuration.
     */
    public function getNestsAndEggs(GetExtensionsRequest $request): JsonResponse
    {
        $nests = Nest::with('eggs')->get();

        $data = $nests->map(function ($nest) {
            return [
                'id' => $nest->id,
                'name' => $nest->name,
                'description' => $nest->description,
                'eggs' => $nest->eggs->map(function ($egg) {
                    return [
                        'id' => $egg->id,
                        'name' => $egg->name,
                        'description' => $egg->description,
                    ];
                }),
            ];
        });

        return new JsonResponse([
            'object' => 'list',
            'data' => $data,
        ]);
    }
}
