<?php

namespace Everest\Extensions\Hooks\Events;

use Everest\Models\Server;
use Everest\Extensions\Hooks\HookEvent;

/**
 * A server's details, startup or build changed and the change has committed.
 *
 * `changed` names the top-level areas that moved rather than diffing every
 * column: a full before/after would leak whatever a future column holds into
 * every installed extension's handler, including a queue payload.
 */
final readonly class ServerUpdatedHook extends HookEvent
{
    /**
     * @param array<int, string> $changed
     */
    private function __construct(
        private int $serverId,
        private string $serverUuid,
        private string $name,
        private int $ownerId,
        private array $changed,
    ) {
        parent::__construct();
    }

    /**
     * @param array<int, string> $changed one or more of details, startup, build
     */
    public static function fromServer(Server $server, array $changed): self
    {
        return new self(
            serverId: (int) $server->id,
            serverUuid: (string) $server->uuid,
            name: (string) $server->name,
            ownerId: (int) $server->owner_id,
            changed: array_values(array_map('strval', $changed)),
        );
    }

    public function name(): string
    {
        return 'server.updated';
    }

    public function toPayload(): array
    {
        return [
            'serverId' => $this->serverId,
            'serverUuid' => $this->serverUuid,
            'serverName' => $this->name,
            'ownerId' => $this->ownerId,
            'changed' => $this->changed,
        ];
    }
}
