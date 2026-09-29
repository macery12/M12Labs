<?php

namespace Everest\Mail;

use Everest\Events\Email\AccountLocked;

class AccountLockedMail extends PanelMail
{
    public const KEY = 'auth.account_locked';

    public function __construct(
        public readonly string $userName,
        public readonly string $reason,
        public readonly string $suspendedAt,
        public readonly string $supportUrl,
    ) {
    }

    public static function fromEvent(AccountLocked $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            reason: $event->reason,
            suspendedAt: self::now(),
            supportUrl: url('/support'),
        );
    }

    protected function subjectLine(): string
    {
        return 'Your Account Has Been Suspended';
    }
}
