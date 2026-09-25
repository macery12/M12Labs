<?php

namespace Everest\Extensions\Sdk\Http;

use Everest\Http\Controllers\Api\Application as Core;
use Everest\Traits\Controllers\RespondsWithExtensionEnvelope;

/**
 * Base controller for an extension's admin endpoints.
 *
 * Subclasses must call `parent::__construct()`.
 *
 * As with the client controller, the response envelope is built in rather than
 * offered as a separate trait.
 */
abstract class ApplicationApiController extends Core\ApplicationApiController
{
    use RespondsWithExtensionEnvelope;
}
