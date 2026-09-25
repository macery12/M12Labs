<?php

namespace Everest\Extensions\Sdk\Services;

use Everest\Models\User;
use Everest\Models\Server;
use Everest\Services\Access\DelegatedGrant;
use Everest\Services\Extensions\ExtensionCallerGuard;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Access\DelegatedAccess as CoreDelegatedAccess;
use Everest\Exceptions\Service\Extension\PrivilegeNotGrantedException;

/**
 * Audited administrator access to a customer's server.
 *
 * The panel normally refuses everyone who is neither the server's owner, a
 * subuser, nor a panel Owner — which makes "my server will not start" the one
 * support request staff cannot act on directly. This opens that door, narrowly
 * and visibly.
 *
 * Requires `delegated_access` under `capabilities.privileged`.
 *
 * What a package can say is which server and why. What it cannot say is what
 * for: core owns the two levels, `read()` and its `escalated()` form, and there
 * is no way to spell an ability list from out here. That is deliberate and it
 * is what makes the feature safe to expose at all — core's policy is
 * consulting a window this package asked to open, so this package must not be
 * able to decide how wide it is.
 *
 * Three things core guarantees whatever a caller does:
 *
 * 1. The administrator must hold the `servers.assist` capability, checked when
 *    the session opens, when it widens, and again on every dispatch inside it.
 *    A package cannot act on behalf of someone who could not act themselves.
 * 2. Opening or widening writes a row into the customer's own activity feed.
 *    It is part of authorization, not telemetry: no grant comes back if the
 *    record cannot be written, and it is written even where the operator has
 *    switched activity logging off. Support access a customer cannot see is
 *    surveillance. The grant is sealed to that row, and `during()` and
 *    `escalate()` refuse any grant whose row does not exist or does not
 *    describe it — so a `DelegatedGrant` built by hand, or edited in storage,
 *    opens nothing. Build one with `DelegatedGrant::read()` only to show an
 *    administrator what they are about to approve.
 * 3. The window is open for the callable passed to `during()` and shut
 *    immediately after, including when that callable throws. It is not open for
 *    the request.
 *
 * ```php
 * $access = DelegatedAccess::for('my_extension');
 *
 * if (!$access->permitted($admin)) {
 *     throw new DisplayException('You do not have permission to assist on customer servers.');
 * }
 *
 * $grant = $access->open($admin, $server, 'Ticket #418 — will not boot', ticketId: 418);
 * $access->during($admin, $grant, fn () => $this->readTheLogs($server));
 * ```
 */
final class DelegatedAccess
{
    public const PRIVILEGE = 'delegated_access';

    private function __construct(
        private string $extensionId,
        private CoreDelegatedAccess $access,
        private ExtensionRuntimePlanService $plan,
    ) {
    }

    /**
     * @throws PrivilegeNotGrantedException when the live runtime plan does not grant it
     * @throws \Everest\Exceptions\Service\Extension\ForeignExtensionIdException when called from another package's code
     */
    public static function for(string $extensionId): self
    {
        ExtensionCallerGuard::assertCallerIs($extensionId);

        $plan = app(ExtensionRuntimePlanService::class);

        if (!$plan->grantsPrivilege($extensionId, self::PRIVILEGE)) {
            throw new PrivilegeNotGrantedException($extensionId, self::PRIVILEGE);
        }

        return new self($extensionId, app(CoreDelegatedAccess::class), $plan);
    }

    /**
     * Whether this administrator may be delegated onto any server at all.
     *
     * Worth asking before offering the action, so a member of staff without the
     * capability sees nothing rather than a button that throws.
     */
    public function permitted(User $admin): bool
    {
        return $this->access->permitted($admin);
    }

    /**
     * Resolve a server from an id, a uuid or the short uuid. Null when nothing
     * matches, rather than throwing.
     */
    public function resolveServer(string $reference): ?Server
    {
        return $this->access->resolveServer($reference);
    }

    /**
     * Open read-only access, and record it where the customer can see.
     *
     * `$reason` is shown to the customer in their own activity feed, alongside
     * this extension's id. Write it for them, not for a log grep.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function open(User $admin, Server $server, string $reason, ?int $ticketId = null): DelegatedGrant
    {
        $this->assertGranted();

        return $this->access->open($admin, $server, $reason, $ticketId, onBehalfOf: $this->extensionId);
    }

    /**
     * Widen an open grant to allow changes, and record that too.
     *
     * Takes the grant already in force rather than a server and a level, so
     * there is no way to escalate onto something else. Deletion is never
     * included, at any level: a support session exists to fix a server, not to
     * destroy part of it, and the customer owns their files.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function escalate(User $admin, Server $server, DelegatedGrant $grant): DelegatedGrant
    {
        $this->assertGranted();

        return $this->access->escalate($admin, $server, $grant, onBehalfOf: $this->extensionId);
    }

    /**
     * Run one action with the grant in force.
     *
     * The only way in. Keep the callable as small as the thing that actually
     * needs the access — everything outside it sees the ordinary refusal, which
     * is the property that makes this narrow rather than a standing exception.
     *
     * @template T
     *
     * @param callable(): T $run
     *
     * @return T
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function during(User $admin, DelegatedGrant $grant, callable $run): mixed
    {
        $this->assertGranted();

        return $this->access->during($admin, $grant, $run);
    }

    /**
     * Re-attach a grant that was stored and read back, or refuse it.
     *
     * Both questions are asked again from scratch: does the server still exist,
     * and does this administrator still hold the capability. An approval that
     * sat on a screen while someone's access profile narrowed must not complete.
     */
    public function reauthorize(User $admin, DelegatedGrant $grant): ?Server
    {
        $this->assertGranted();

        return $this->access->reauthorize($admin, $grant);
    }

    /**
     * Recheck immediately before opening, widening, using, or resuming a grant.
     * A retained facade must lose authority as soon as the live package does.
     *
     * @throws PrivilegeNotGrantedException
     */
    private function assertGranted(): void
    {
        if (!$this->plan->grantsPrivilege($this->extensionId, self::PRIVILEGE)) {
            throw new PrivilegeNotGrantedException($this->extensionId, self::PRIVILEGE);
        }
    }
}
