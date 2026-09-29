<?php

namespace Everest\Mail;

use Everest\Events\Email\TwoFactorDisabled;

class TwoFactorDisabledMail extends PanelMail
{
    public const KEY = 'auth.2fa_disabled';

    public function __construct(
        public readonly string $userName,
        public readonly string $disabledAt,
        public readonly string $ipAddress,
    ) {
    }

    public static function fromEvent(TwoFactorDisabled $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            disabledAt: self::now(),
            ipAddress: request()->ip() ?? 'Unknown',
        );
    }

    protected function subjectLine(): string
    {
        return 'Two-Factor Authentication Disabled';
    }
}
