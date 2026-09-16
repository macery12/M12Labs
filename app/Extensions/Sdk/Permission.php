<?php

namespace Everest\Extensions\Sdk;

use Everest\Models\Permission as Core;

/**
 * The core server-permission vocabulary, for a client FormRequest's
 * `permission()`.
 *
 * Abstract on purpose. Packages need the constants — `Permission::ACTION_FILE_READ`
 * and the rest — and have no business querying the table, which is core's to
 * own. Eloquent's `query()` instantiates `static`, so declaring this abstract
 * makes "constants only" a property of the class rather than a convention.
 *
 * Extensions cannot contribute to this vocabulary: a package's admin
 * permissions are declared in its manifest and resolve to
 * `ext.<id>.admin.<action>`. There is no equivalent for server permissions yet,
 * so a server page reuses a core permission (see docs/extensions/php-sdk.md).
 */
abstract class Permission extends Core
{
}
