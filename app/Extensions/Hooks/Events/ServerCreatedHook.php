<?php

namespace Everest\Extensions\Hooks\Events;

use Everest\Models\Server;
use Everest\Extensions\Hooks\HookEvent;

/** A server exists and its creating transaction has committed. */
final readonly class ServerCreatedHook extends HookEvent
{
    private function __construct(
        private int $serverId,
        private string $serverUuid,
        private string $name,
        private int $ownerId,
        private int $nodeId,
        private int $nestId,
        private int $eggId,
    ) {
        parent::__construct();
    }

    public static function fromServer(Server $server): self
    {
        return new self(
            serverId: (int) $server->id,
            serverUuid: (string) $server->uuid,
            name: (string) $server->name,
            ownerId: (int) $server->owner_id,
            nodeId: (int) $server->node_id,
            nestId: (int) $server->nest_id,
            eggId: (int) $server->egg_id,
        );
    }

    public function name(): string
    {
        return 'server.created';
    }

    public function toPayload(): array
    {
        return [
            'serverId' => $this->serverId,
            'serverUuid' => $this->serverUuid,
            'serverName' => $this->name,
            'ownerId' => $this->ownerId,
            'nodeId' => $this->nodeId,
            'nestId' => $this->nestId,
            'eggId' => $this->eggId,
        ];
    }
}
