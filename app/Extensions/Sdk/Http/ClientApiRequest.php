<?php

namespace Everest\Extensions\Sdk\Http;

use Everest\Http\Requests\Api\Client as Core;

/**
 * Base FormRequest for an extension's per-server endpoints.
 *
 * Identical to the panel's own client request — it is a subclass, not a
 * reimplementation, so authorization behaves exactly as it does for a core
 * endpoint and cannot drift. Implementing {@see ClientPermissionsRequest} and
 * returning a core permission from `permission()` is what actually gates the
 * request; the loader's `extensions.access` middleware decides whether the
 * extension may be reached at all, not whether this caller may do this thing.
 *
 * @method \Everest\Models\User user($guard = null)
 */
abstract class ClientApiRequest extends Core\ClientApiRequest
{
}
