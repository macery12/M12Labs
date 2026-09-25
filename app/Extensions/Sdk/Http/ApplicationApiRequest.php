<?php

namespace Everest\Extensions\Sdk\Http;

use Everest\Http\Requests\Api\Application as Core;

/**
 * Base FormRequest for an extension's admin endpoints.
 *
 * `permission()` returns the full `ext.<id>.admin.<action>` identifier the
 * manifest's declared action segment resolves to — the panel derives the
 * prefix, so a package cannot mint a permission in another extension's
 * namespace or in core's.
 */
abstract class ApplicationApiRequest extends Core\ApplicationApiRequest
{
}
