<?php

namespace Everest\Extensions\Sdk;

use Everest\Models\AdminRole as Core;

/**
 * The core admin-capability vocabulary.
 *
 * The sibling of {@see Permission}, which does the same job for a server's
 * subuser permissions. Packages need the constants — `AdminCapability::USERS_READ`
 * and the rest — to ask {@see Services\AdminAuthorization::holds()} whether an
 * administrator may do something, and have no business querying the roles
 * table, which is core's to own. Eloquent's `query()` instantiates `static`, so
 * declaring this abstract makes "constants only" a property of the class rather
 * than a convention.
 *
 * This is for asking about *core's* capabilities, which is what a package needs
 * when it describes or narrows what an administrator can reach elsewhere in the
 * panel. A package's own admin permissions are declared in its manifest and
 * resolve to `ext.<id>.admin.<action>`; those are returned from a FormRequest's
 * `permission()` as plain strings and are not part of this vocabulary.
 *
 * Constants only, and named ones at that. Anything a package spells as a
 * literal keeps working if core renames a capability — it just stops matching,
 * and the feature disappears rather than opening up — but naming it here means
 * the rename is a build error instead.
 */
abstract class AdminCapability extends Core
{
}
