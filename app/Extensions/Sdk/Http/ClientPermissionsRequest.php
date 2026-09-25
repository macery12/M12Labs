<?php

namespace Everest\Extensions\Sdk\Http;

use Everest\Contracts\Http\ClientPermissionsRequest as Core;

/**
 * Marks a client FormRequest as gated on a core server permission.
 *
 * A sub-interface, so core's `authorize()` — which checks against the core
 * contract — still recognises it.
 */
interface ClientPermissionsRequest extends Core
{
}
