<?php

namespace Everest\Mail;

use Everest\Events\Email\TwoFactorEnabled;

class TwoFactorEnabledMail extends PanelMail
{
    public const KEY = 'auth.2fa_enabled';

    public function __construct(
        public readonly string $userName,
        public readonly string $enabledAt,
    ) {
    }

    public static function fromEvent(TwoFactorEnabled $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            enabledAt: self::now(),
        );
    }

    protected function subjectLine(): string
    {
        return 'Two-Factor Authentication Enabled';
    }
}
