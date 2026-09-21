<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\User;
use Everest\Services\Authorization\AdminAuthorizer;

/**
 * What an administrator is allowed to do, as yes/no questions.
 *
 * A package's own admin permissions are declared in its manifest and enforced
 * by the route middleware before a controller runs, so most code never needs
 * this. It is for the cases where a package has to *describe* an admin's
 * authority rather than be gated by it -- telling a user which of its features
 * they can reach, or narrowing a list of actions to the ones that would
 * succeed.
 *
 * Deliberately only booleans. `AdminAuthorizer` can also hand back the role
 * record and the raw capability list, and neither is exposed here: a package
 * reading core's admin roles would be reaching past the permission vocabulary
 * the manifest gave it into one it cannot see the shape of. Ask about a
 * specific capability instead.
 */
final class AdminAuthorization
{
    private function __construct(private AdminAuthorizer $authorizer)
    {
    }

    public static function reader(): self
    {
        return new self(app(AdminAuthorizer::class));
    }

    /**
     * The panel owner, who holds every capability implicitly.
     *
     * Worth asking separately from `holds()`, because an owner's capability
     * list is the literal `['*']` -- a package listing what somebody can do
     * needs to know the difference between "all of them" and "these".
     */
    public function isOwner(User $user): bool
    {
        return $this->authorizer->isOwner($user);
    }

    /** Whether this user can reach the admin area at all. */
    public function canAccessAdmin(User $user): bool
    {
        return $this->authorizer->canAccessAdmin($user);
    }

    /** Whether this user holds one named capability, owner wildcard included. */
    public function holds(User $user, string $capability): bool
    {
        return $this->authorizer->hasCapability($user, $capability);
    }
}
