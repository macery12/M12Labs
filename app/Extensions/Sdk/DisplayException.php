<?php

namespace Everest\Extensions\Sdk;

use Everest\Exceptions\DisplayException as Core;

/**
 * An error safe to render to the caller.
 *
 * Extensions throw this rather than building error JSON by hand; the panel's
 * exception handler renders it into the same envelope core errors use. A
 * subclass, so core's `catch (DisplayException)` still catches it.
 */
class DisplayException extends Core
{
}
