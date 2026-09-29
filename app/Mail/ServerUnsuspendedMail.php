<?php

namespace Everest\Mail;

use Everest\Events\Email\ServerUnsuspended;

class ServerUnsuspendedMail extends PanelMail
{
    public const KEY = 'server.unsuspended';

    public function __construct(
        public readonly string $userName,
        public readonly string $serverName,
        public readonly string $unsuspendedAt,
    ) {
    }

    public static function fromEvent(ServerUnsuspended $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            serverName: $event->server->name,
            unsuspendedAt: self::now(),
        );
    }

    protected function subjectLine(): string
    {
        return 'Your Server Has Been Unsuspended';
    }
}
