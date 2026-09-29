<?php

namespace Everest\Mail;

use Everest\Events\Email\PasswordResetRequested;

class PasswordResetMail extends PanelMail
{
    public const KEY = 'auth.password_reset';

    public function __construct(
        public readonly string $userName,
        public readonly string $resetUrl,
        public readonly string $expiresIn,
    ) {
    }

    public static function fromEvent(PasswordResetRequested $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            resetUrl: $event->resetUrl,
            expiresIn: '60 minutes',
        );
    }

    protected function subjectLine(): string
    {
        return 'Reset Your Password';
    }
}
