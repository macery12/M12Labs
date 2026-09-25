<?php

namespace Everest\Extensions\Sdk\Http;

use Everest\Http\Controllers\Api\Client as Core;
use Everest\Traits\Controllers\RespondsWithExtensionEnvelope;

/**
 * Base controller for an extension's per-server endpoints.
 *
 * Subclasses must call `parent::__construct()`; the panel resolves Fractal and
 * the request through it.
 *
 * The response envelope comes with it — `extensionListResponse()` and
 * `extensionItemResponse()` are available to every subclass. Building it into
 * the base class rather than offering a separate trait means a package cannot
 * extend this and then hand-roll a different response shape by forgetting an
 * import.
 */
abstract class ClientApiController extends Core\ClientApiController
{
    use RespondsWithExtensionEnvelope;
}
