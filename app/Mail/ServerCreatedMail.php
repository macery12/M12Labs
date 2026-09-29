<?php

namespace Everest\Mail;

use Everest\Events\Email\ServerCreatedEmail;

class ServerCreatedMail extends PanelMail
{
    public const KEY = 'server.created';

    public function __construct(
        public readonly string $userName,
        public readonly string $serverName,
        public readonly string $serverId,
        public readonly string $serverUrl,
        public readonly string $nodeLocation,
    ) {
    }

    public static function fromEvent(ServerCreatedEmail $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            serverName: $event->server->name,
            serverId: $event->server->uuidShort,
            serverUrl: url("/server/{$event->server->uuidShort}"),
            nodeLocation: $event->server->node->name ?? 'Unknown',
        );
    }

    protected function subjectLine(): string
    {
        return 'Your Server Has Been Created';
    }
}
