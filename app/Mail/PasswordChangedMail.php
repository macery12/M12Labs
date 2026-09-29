<?php

namespace Everest\Mail;

use Everest\Events\Email\PasswordChanged;

class PasswordChangedMail extends PanelMail
{
    public const KEY = 'auth.password_changed';

    public function __construct(
        public readonly string $userName,
        public readonly string $changedAt,
        public readonly string $ipAddress,
    ) {
    }

    public static function fromEvent(PasswordChanged $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            changedAt: self::now(),
            ipAddress: request()->ip() ?? 'Unknown',
        );
    }

    protected function subjectLine(): string
    {
        return 'Your Password Has Been Changed';
    }
}
