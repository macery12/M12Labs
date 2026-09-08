<?php

namespace Everest\Extensions\Hooks\Events;

use Everest\Models\Server;
use Everest\Models\Allocation;
use Everest\Extensions\Hooks\HookEvent;

/**
 * A server's allocations changed. Carries the full resulting set, because a
 * handler that maps DNS or firewall state to allocations needs the outcome
 * rather than the delta.
 */
final readonly class ServerAllocationChangedHook extends HookEvent
{
    /**
     * @param array<int, array{id: int, ip: string, port: int, isPrimary: bool}> $allocations
     */
    private function __construct(
        private int $serverId,
        private string $serverUuid,
        private int $primaryAllocationId,
        private array $allocations,
    ) {
        parent::__construct();
    }

    public static function fromServer(Server $server): self
    {
        // Materialized here, not lazily in the payload: for a queued handler
        // the relation would otherwise be re-read at execution time, against a
        // database that has moved on.
        $allocations = $server->allocations()->get(['id', 'ip', 'port'])
            ->map(fn (Allocation $allocation): array => [
                'id' => (int) $allocation->id,
                'ip' => (string) $allocation->ip,
                'port' => (int) $allocation->port,
                'isPrimary' => (int) $allocation->id === (int) $server->allocation_id,
            ])
            ->values()
            ->all();

        return new self(
            serverId: (int) $server->id,
            serverUuid: (string) $server->uuid,
            primaryAllocationId: (int) $server->allocation_id,
            allocations: $allocations,
        );
    }

    public function name(): string
    {
        return 'server.allocation_changed';
    }

    public function toPayload(): array
    {
        return [
            'serverId' => $this->serverId,
            'serverUuid' => $this->serverUuid,
            'primaryAllocationId' => $this->primaryAllocationId,
            'allocations' => $this->allocations,
        ];
    }
}
