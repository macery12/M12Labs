<?php

namespace Everest\Http\Controllers\Api\Client\Extensions;

use Illuminate\Http\JsonResponse;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Services\Extensions\ExtensionFrontendFlagService;

/** Return the same non-sensitive package state embedded in the page bootstrap. */
class ExtensionFlagsController extends ClientApiController
{
    public function __construct(private ExtensionFrontendFlagService $flags)
    {
        parent::__construct();
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'object' => 'extension_flags',
            'attributes' => $this->flags->snapshot(),
        ]);
    }
}
