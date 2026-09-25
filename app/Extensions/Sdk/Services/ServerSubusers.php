<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\Server;
use Everest\Models\Subuser;
use Illuminate\Support\Collection;

/**
 * Reading a server's subusers and what they may do.
 *
 * Read-only. Granting or revoking access is core's, and an extension able to
 * add a subuser would be an extension able to grant server access -- which is
 * the one capability the platform deliberately does not hand out.
 *
 * Use this to *reflect* an existing ACL, not to re-implement one. A package
 * deciding for itself whether a user may act has already gone wrong: that
 * decision belongs in the FormRequest's `permission()`, against the core
 * permission vocabulary, where core evaluates it.
 */
final class ServerSubusers
{
    private function __construct(private Server $server)
    {
    }

    public static function for(Server $server): self
    {
        return new self($server);
    }

    /** @return Collection<int, Subuser> */
    public function all(): Collection
    {
        return Subuser::query()->where('server_id', $this->server->id)->get();
    }

    public function forUser(int $userId): ?Subuser
    {
        return Subuser::query()
            ->where('server_id', $this->server->id)
            ->where('user_id', $userId)
            ->first();
    }

    /** Whether this user holds a core permission on this server. */
    public function holds(int $userId, string $permission): bool
    {
        $subuser = $this->forUser($userId);

        if ($subuser === null) {
            return false;
        }

        $permissions = (array) $subuser->permissions;

        return in_array($permission, $permissions, true)
            || in_array('*', $permissions, true);
    }
}
