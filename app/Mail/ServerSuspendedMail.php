<?php

namespace Everest\Mail;

use Everest\Events\Email\ServerSuspended;

class ServerSuspendedMail extends PanelMail
{
    public const KEY = 'server.suspended';

    public function __construct(
        public readonly string $userName,
        public readonly string $serverName,
        public readonly string $reason,
        public readonly string $suspendedAt,
    ) {
    }

    public static function fromEvent(ServerSuspended $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            serverName: $event->server->name,
            reason: $event->reason,
            suspendedAt: self::now(),
        );
    }

    protected function subjectLine(): string
    {
        return 'Your Server Has Been Suspended';
    }
}
