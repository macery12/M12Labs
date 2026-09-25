<?php

namespace Everest\Services\Extensions;

use Everest\Models\User;
use Everest\Models\AdminRole;
use Everest\Services\Authorization\AdminAuthorizer;

/**
 * Whether an administrator may change a package's configuration — its settings
 * or its credentials — from that package's own settings page.
 *
 * Two ways to qualify. Core's `extensions.update` is what the panel's generated
 * settings form requires, so holding it always suffices. But it also allows
 * installing, uninstalling and toggling every extension, which is far more
 * than "may configure the AI assistant". So a package's page may name one of
 * the admin permissions *that package declared* — the one its own routes are
 * gated by — and an administrator the operator granted exactly that permission
 * qualifies too.
 *
 * What a package cannot do is widen who that is: the permission must be one
 * the package declared (and so one the operator saw at install and assigns
 * deliberately), in its own `ext.<id>.admin.` namespace, read from the live
 * runtime plan.
 */
class ExtensionConfigurationAuthorizer
{
    public function __construct(
        private AdminAuthorizer $authorizer,
        private ExtensionRuntimePlanService $plan,
    ) {
    }

    public function mayConfigure(User $actor, string $extensionId, ?string $permission = null): bool
    {
        if ($this->authorizer->hasCapability($actor, AdminRole::EXTENSIONS_UPDATE)) {
            return true;
        }

        return $permission !== null
            && $this->declares($extensionId, $permission)
            && $this->authorizer->hasCapability($actor, $permission);
    }

    /**
     * Whether `$permission` is one of the admin permissions this package
     * declared, as its full `ext.<id>.admin.<action>` identifier.
     */
    public function declares(string $extensionId, string $permission): bool
    {
        $entry = $this->plan->entry($extensionId);
        if ($entry === null) {
            return false;
        }

        foreach ($entry->capabilities->adminPermissions as $declared) {
            if ($declared->identifier($extensionId) === $permission) {
                return true;
            }
        }

        return false;
    }
}
