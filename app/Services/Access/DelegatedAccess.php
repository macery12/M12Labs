<?php

namespace Everest\Services\Access;

use Everest\Models\User;
use Everest\Models\Server;
use Everest\Facades\Activity;
use Everest\Models\AdminRole;
use Illuminate\Auth\Access\AuthorizationException;
use Everest\Services\Authorization\AdminAuthorizer;

/**
 * Delegated, audited administrator access to a customer's server — the way past
 * a boundary that otherwise makes "my server won't start" the one request
 * support cannot help with.
 *
 * This is the door. {@see DelegatedSession} is only the window it opens onto,
 * and {@see DelegatedGrant} only describes what is on the other side; both are
 * inert without a decision made here. So this class is the whole of the
 * feature's security decision — who is allowed in, into which server, and what
 * gets written down — in one place a reviewer can find.
 *
 * Four properties hold no matter who is calling:
 *
 * 1. The administrator must hold `AdminRole::SERVERS_ASSIST`, checked on the way
 *    in *and* again on every resume, never read from stored state — an
 *    administrator whose Access Profile narrowed while an approval sat on screen
 *    must not complete it by clicking Approve.
 * 2. Authority is one of two levels this package names. A caller says which
 *    server and why; it never says what for.
 * 3. Opening or widening writes an activity row against the server, landing in
 *    the customer's own feed. Support access a customer cannot see is
 *    surveillance, so the write is part of authorization: the grant is not
 *    returned unless the record exists.
 * 4. The window is open for one dispatched action, not for the request.
 *
 * Born inside the AI module, which is still its only caller. It lives in core
 * because the decision is core's: an installed extension that could widen what
 * `ServerPolicy` allows would be a privilege-escalation surface, and this is the
 * shape that avoids needing one.
 */
class DelegatedAccess
{
    public function __construct(
        private AdminAuthorizer $authorizer,
        private DelegatedSession $session,
    ) {
    }

    /**
     * Whether this administrator may be delegated onto any server at all.
     */
    public function permitted(User $admin): bool
    {
        return $this->authorizer->hasCapability($admin, AdminRole::SERVERS_ASSIST);
    }

    /**
     * Resolve a server from an id, a uuid or the short uuid.
     *
     * All three are accepted because callers get them from different listings
     * and being strict here only produces a retry that looks like a fault.
     * Returns null when nothing matches, rather than throwing — a caller turns
     * that into something the operator can act on.
     */
    public function resolveServer(string $reference): ?Server
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        $query = Server::query();

        if (ctype_digit($reference)) {
            return $query->find((int) $reference);
        }

        return $query
            ->where(strlen($reference) === 8 ? 'uuidShort' : 'uuid', $reference)
            ->first();
    }

    /**
     * Open read-only delegated access, and record it where the customer can see.
     *
     * @throws AuthorizationException
     */
    public function open(
        User $admin,
        Server $server,
        string $reason,
        ?int $ticketId = null,
        ?string $onBehalfOf = null,
    ): DelegatedGrant {
        $this->assertPermitted($admin);

        $grant = DelegatedGrant::read($server->uuid, (string) $server->name, $reason, $ticketId);

        $this->record($admin, $server, $grant, onBehalfOf: $onBehalfOf);

        return $grant;
    }

    /**
     * Widen an open grant to allow changes.
     *
     * Takes the grant presently in force rather than a server and a level, so
     * there is no way to spell "escalate onto something else": the target and
     * the reason come from what was already approved.
     *
     * @throws AuthorizationException
     */
    public function escalate(
        User $admin,
        Server $server,
        DelegatedGrant $current,
        ?string $onBehalfOf = null,
    ): DelegatedGrant {
        $this->assertPermitted($admin);

        if ($current->serverUuid !== $server->uuid) {
            throw new AuthorizationException('A delegated session cannot be escalated onto another server.');
        }

        $escalated = $current->escalated();

        $this->record($admin, $server, $escalated, escalation: true, onBehalfOf: $onBehalfOf);

        return $escalated;
    }

    /**
     * Run one action with the grant in force.
     *
     * Asked here rather than of the session directly, so one object owns "may
     * they, and while they do" and the window stays as short as the call it
     * wraps. The capability is re-checked because time passes between opening a
     * session and using it.
     *
     * @template T
     *
     * @param callable(): T $run
     *
     * @return T
     *
     * @throws AuthorizationException
     */
    public function during(User $admin, DelegatedGrant $grant, callable $run): mixed
    {
        $this->assertPermitted($admin);

        return $this->session->during($admin, $grant, $run);
    }

    /**
     * Re-attach a restored grant to its server, or refuse it.
     *
     * Called on resume, where everything about the grant arrived from storage.
     * Both questions are asked again from scratch: does the server still exist,
     * and does this administrator still hold the capability.
     */
    public function reauthorize(User $admin, DelegatedGrant $grant): ?Server
    {
        if (!$this->permitted($admin)) {
            return null;
        }

        return Server::query()->where('uuid', $grant->serverUuid)->first();
    }

    /**
     * Write the session into the server's own activity feed, so the customer
     * sees — beside their own logins and file edits — that a named member of
     * staff looked inside their server, when, and why.
     *
     * Failure here is authorization failure, which is why callers of `open()`
     * and `escalate()` get an exception rather than a grant.
     */
    private function record(
        User $admin,
        Server $server,
        DelegatedGrant $grant,
        bool $escalation = false,
        ?string $onBehalfOf = null,
    ): void {
        Activity::event($escalation ? 'server:access.delegated.escalate' : 'server:access.delegated.start')
            ->actor($admin)
            ->subject($server)
            ->property(array_merge([
                'administrator' => $admin->username,
                'reason' => $grant->reason,
                'ticket_id' => $grant->ticketId,
                'abilities' => $grant->abilities,
            ], $onBehalfOf === null ? [] : [
                // Which software opened it. Without this a customer reading
                // their own feed, or an operator reading all of them, cannot
                // tell one extension's support session from another's. Absent
                // rather than null when core itself asked, so the rows that
                // already exist keep their exact shape.
                'via' => $onBehalfOf,
            ]))
            ->log();
    }

    /**
     * @throws AuthorizationException
     */
    private function assertPermitted(User $admin): void
    {
        if (!$this->permitted($admin)) {
            throw new AuthorizationException('This account may not be delegated onto a customer\'s server.');
        }
    }
}
