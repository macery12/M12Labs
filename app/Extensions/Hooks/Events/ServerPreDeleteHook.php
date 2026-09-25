<?php

namespace Everest\Extensions\Hooks\Events;

use Everest\Models\Server;
use Everest\Models\Allocation;
use Everest\Extensions\Hooks\HookEvent;

/**
 * A server is about to be deleted. Dispatched before the transaction, while the
 * server row and everything hanging off it still exists.
 *
 * This is the only ordering that works. Extension tables reference servers with
 * cascadeOnDelete, so their rows vanish at the instant the server row is
 * deleted — an "after delete" hook reads an empty table and cleans up nothing.
 * A synchronous handler can therefore read its own rows; a queued one runs
 * after the cascade and must read the tombstone instead.
 */
final readonly class ServerPreDeleteHook extends HookEvent
{
    /**
     * @param array<int, array{id: int, ip: string, port: int}> $allocations
     */
    private function __construct(
        private int $serverId,
        private string $serverUuid,
        private string $name,
        private int $ownerId,
        private int $nodeId,
        private bool $force,
        private array $allocations,
    ) {
        parent::__construct();
    }

    public static function fromServer(Server $server, bool $force = false): self
    {
        // Everything a handler could want is read now, while it still exists.
        // Nothing lazy survives into the payload.
        $allocations = $server->allocations()->get(['id', 'ip', 'port'])
            ->map(fn (Allocation $allocation): array => [
                'id' => (int) $allocation->id,
                'ip' => (string) $allocation->ip,
                'port' => (int) $allocation->port,
            ])
            ->values()
            ->all();

        return new self(
            serverId: (int) $server->id,
            serverUuid: (string) $server->uuid,
            name: (string) $server->name,
            ownerId: (int) $server->owner_id,
            nodeId: (int) $server->node_id,
            force: $force,
            allocations: $allocations,
        );
    }

    public function name(): string
    {
        return 'server.pre_delete';
    }

    public function toPayload(): array
    {
        return [
            'serverId' => $this->serverId,
            'serverUuid' => $this->serverUuid,
            'serverName' => $this->name,
            'ownerId' => $this->ownerId,
            'nodeId' => $this->nodeId,
            'force' => $this->force,
            'allocations' => $this->allocations,
        ];
    }
}
