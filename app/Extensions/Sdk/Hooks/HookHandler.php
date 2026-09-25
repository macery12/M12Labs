<?php

namespace Everest\Extensions\Sdk\Hooks;

use Everest\Extensions\Hooks\HookHandler as Core;

/**
 * Handles one declared core event.
 *
 * A sub-interface so ExtensionHookDispatcher, which checks against the core
 * contract, still recognises the handler. Delivery is one-way and best effort:
 * the return value is discarded, and a hook cannot abort the operation that
 * raised it.
 */
interface HookHandler extends Core
{
}
