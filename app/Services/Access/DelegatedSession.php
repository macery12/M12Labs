<?php

namespace Everest\Services\Access;

use Everest\Models\User;
use Everest\Models\Server;

/**
 * The window during which a delegated grant is actually in force — the single
 * exception `AuthenticateServerAccess` and `ServerPolicy` consult for a
 * delegated administrator who is neither the server owner nor a panel Owner.
 *
 * Kept as small as possible: not open for the request, turn or conversation.
 * The caller opens it around one dispatched action and closes it in a `finally`,
 * so anything else in the same PHP request sees the ordinary refusal.
 * `during()` is the only way in, so no code path opens a session and forgets to
 * close it.
 *
 * Reached through {@see DelegatedAccess}, which is what checks that the
 * administrator may be here at all. This class holds no opinion about that: it
 * is the window, not the door. Nothing outside core should hold a reference to
 * it, for exactly that reason.
 *
 * A singleton, since its two consumers are a middleware and a policy that the
 * caller could not otherwise reach.
 */
class DelegatedSession
{
    private ?DelegatedGrant $grant = null;

    private ?int $userId = null;

    /**
     * Run something with the grant in force, and take it back out again.
     *
     * @template T
     *
     * @param callable(): T $run
     *
     * @return T
     */
    public function during(User $user, DelegatedGrant $grant, callable $run): mixed
    {
        // Nesting would let an inner grant's narrower authority be silently
        // replaced by a wider one on the way out. Nothing nests today; this is
        // here so that stays true by construction rather than by convention.
        if ($this->grant !== null) {
            throw new \LogicException('A delegated session is already open.');
        }

        $this->grant = $grant;
        $this->userId = $user->id;

        try {
            return $run();
        } finally {
            $this->grant = null;
            $this->userId = null;
        }
    }

    /**
     * Whether this user is presently delegated onto this server at all.
     *
     * Consulted by `AuthenticateServerAccess`, which asks only whether the door
     * opens — the endpoint's own permission gate decides what is behind it.
     */
    public function covers(User $user, Server $server): bool
    {
        return $this->grant !== null
            && $this->userId === $user->id
            && $this->grant->serverUuid === $server->uuid;
    }

    /**
     * Whether this user may take one specific action on this server right now.
     *
     * Consulted by `ServerPolicy::before()`. A grant carries an explicit list,
     * so an ability outside it is refused exactly as it would be for anybody
     * else — which is what keeps a read-only session read-only even if an action
     * it was never offered somehow reaches dispatch.
     */
    public function permits(User $user, Server $server, string $ability): bool
    {
        return $this->covers($user, $server) && $this->grant?->permits($ability) === true;
    }

    /**
     * The grant in force, if any. For diagnostics; authorization should ask one
     * of the two questions above rather than reading this.
     */
    public function current(): ?DelegatedGrant
    {
        return $this->grant;
    }
}
