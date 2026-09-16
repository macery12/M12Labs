<?php

namespace Everest\Services\Access;

use Everest\Models\Permission;

/**
 * One administrator's delegated authority over one customer's server.
 *
 * Deliberately not constructible by name: there is no public constructor and no
 * way to pass an ability list in. A grant is either read-only or the escalation
 * of a read-only one, and the abilities come from the two lists below. Callers
 * outside core — the AI package that asks for a session, anything that asks
 * later — can therefore describe *which server and why*, and never *what for*.
 *
 * Holding one of these confers nothing on its own. It becomes authority only
 * while {@see DelegatedSession} has it in force, and only
 * {@see DelegatedAccess} opens that window.
 */
final class DelegatedGrant
{
    /**
     * What a fresh grant allows: everything needed to answer "why won't it
     * start", and nothing that could change the answer.
     *
     * `websocket.connect` is what the resources endpoint authorises against, so
     * it is a read here despite the name. It does not confer a console socket.
     */
    public const READ_ABILITIES = [
        Permission::ACTION_WEBSOCKET_CONNECT,
        Permission::ACTION_ACTIVITY_READ,
        Permission::ACTION_STARTUP_READ,
        Permission::ACTION_FILE_READ,
        Permission::ACTION_FILE_READ_CONTENT,
    ];

    /**
     * What escalation adds. Deletion is absent and stays absent — a delegated
     * session exists to fix a server, not destroy part of it.
     *
     * `startup.docker-image` sits apart from `startup.update` as the panel keeps
     * it apart: the image is the runtime, not a setting the runtime reads, and it
     * answers the commonest form of "it used to start and now it doesn't."
     */
    public const WRITE_ABILITIES = [
        Permission::ACTION_FILE_CREATE,
        Permission::ACTION_FILE_UPDATE,
        Permission::ACTION_STARTUP_UPDATE,
        Permission::ACTION_STARTUP_DOCKER_IMAGE,
        Permission::ACTION_CONTROL_START,
        Permission::ACTION_CONTROL_STOP,
        Permission::ACTION_CONTROL_RESTART,
        Permission::ACTION_CONTROL_CONSOLE,
    ];

    /** The only keys a stored grant may carry. */
    private const KEYS = ['server_uuid', 'server_name', 'reason', 'abilities', 'ticket_id', 'writable'];

    /**
     * @param string[] $abilities
     */
    private function __construct(
        public readonly string $serverUuid,
        public readonly string $serverName,
        public readonly string $reason,
        public readonly array $abilities,
        public readonly ?int $ticketId,
        public readonly bool $writable,
    ) {
    }

    /**
     * Describe a read-only grant.
     *
     * This mints a *value*, not access — {@see DelegatedAccess::open()} is what
     * checks the capability and writes the customer-visible record. Callers
     * build one of these ahead of approval so an approval card can show exactly
     * what is about to be granted, and to whose server.
     */
    public static function read(
        string $serverUuid,
        string $serverName,
        string $reason,
        ?int $ticketId = null,
    ): self {
        return new self($serverUuid, $serverName, $reason, self::READ_ABILITIES, $ticketId, false);
    }

    /**
     * The same grant with writes added.
     */
    public function escalated(): self
    {
        return new self(
            $this->serverUuid,
            $this->serverName,
            $this->reason,
            self::abilitiesFor(true),
            $this->ticketId,
            true,
        );
    }

    /**
     * The canonical ability list for a level. The one source both minting and
     * {@see fromArray()} read, so a stored grant cannot describe a set that
     * could not have been issued.
     *
     * @return string[]
     */
    public static function abilitiesFor(bool $writable): array
    {
        return $writable
            ? array_values(array_unique(array_merge(self::READ_ABILITIES, self::WRITE_ABILITIES)))
            : self::READ_ABILITIES;
    }

    public function permits(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    public function toArray(): array
    {
        return [
            'server_uuid' => $this->serverUuid,
            'server_name' => $this->serverName,
            'reason' => $this->reason,
            'abilities' => $this->abilities,
            'ticket_id' => $this->ticketId,
            'writable' => $this->writable,
        ];
    }

    /** Whether two values grant exactly the same target and authority. */
    public function sameAuthorityAs(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /**
     * Rebuild from persisted state.
     *
     * The stored form must match one of the two canonical ability sets exactly.
     * Whatever holds a suspended grant is the one part a bug elsewhere could
     * widen, so unknown, missing, or reordered authority is rejected rather than
     * normalized into something usable.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (!is_array($stored)) {
            return null;
        }

        // Nothing but the canonical keys. An earlier shape of this value also
        // carried the tool names a session advertised; those are derived now and
        // nothing reads them back, so a blob that still has them — or has picked
        // up anything else — is refused rather than partly believed.
        if (array_diff_key($stored, array_flip(self::KEYS)) !== []) {
            return null;
        }

        $uuid = $stored['server_uuid'] ?? null;
        $name = $stored['server_name'] ?? null;
        $reason = $stored['reason'] ?? null;
        $writable = $stored['writable'] ?? null;
        $ticket = $stored['ticket_id'] ?? null;

        if (
            !is_string($uuid) || $uuid === ''
            || !is_string($name)
            || !is_string($reason)
            || !is_bool($writable)
            || ($ticket !== null && !is_int($ticket))
        ) {
            return null;
        }

        $abilities = $stored['abilities'] ?? null;
        $expected = self::abilitiesFor($writable);

        if (!is_array($abilities) || array_values($abilities) !== $expected) {
            return null;
        }

        return new self($uuid, $name, $reason, $expected, $ticket, $writable);
    }
}
