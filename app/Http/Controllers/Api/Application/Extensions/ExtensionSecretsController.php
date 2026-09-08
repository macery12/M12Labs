<?php

namespace Everest\Http\Controllers\Api\Application\Extensions;

use Everest\Facades\Activity;
use Illuminate\Http\JsonResponse;
use Everest\Services\Extensions\ExtensionSecretStore;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Http\Requests\Api\Application\Extensions\GetExtensionsRequest;
use Everest\Http\Requests\Api\Application\Extensions\DeleteExtensionSecretRequest;
use Everest\Http\Requests\Api\Application\Extensions\UpdateExtensionSecretRequest;

/**
 * Read and write an extension's credentials.
 *
 * The one invariant: no response body ever contains a secret value. Reads
 * return whether a key is configured and when it last changed; writes are
 * blind. The activity log records the key, never the value — which is also why
 * the value is not simply folded into the settings endpoint, where it would
 * ride in a payload the catalog API echoes back.
 */
class ExtensionSecretsController extends ApplicationApiController
{
    public function __construct(private ExtensionSecretStore $secrets)
    {
        parent::__construct();
    }

    public function index(GetExtensionsRequest $request, string $extensionId): JsonResponse
    {
        return new JsonResponse([
            'object' => 'list',
            'data' => $this->secrets->describe($extensionId),
        ]);
    }

    public function update(UpdateExtensionSecretRequest $request, string $extensionId, string $key): JsonResponse
    {
        $this->secrets->put($extensionId, $key, (string) $request->input('value'), $request->user()->id);

        Activity::event('admin:extensions:secret-update')
            ->property('extension_id', $extensionId)
            ->property('key', $key)
            ->log();

        return new JsonResponse([
            'object' => 'list',
            'data' => $this->secrets->describe($extensionId),
        ]);
    }

    public function destroy(DeleteExtensionSecretRequest $request, string $extensionId, string $key): JsonResponse
    {
        $this->secrets->forget($extensionId, $key);

        Activity::event('admin:extensions:secret-delete')
            ->property('extension_id', $extensionId)
            ->property('key', $key)
            ->log();

        return new JsonResponse([
            'object' => 'list',
            'data' => $this->secrets->describe($extensionId),
        ]);
    }
}
