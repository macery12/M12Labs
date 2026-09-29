<?php

namespace Everest\Mail;

use Everest\Events\Email\AccountUnsuspended;

class AccountUnsuspendedMail extends PanelMail
{
    public const KEY = 'auth.account_unsuspended';

    public function __construct(
        public readonly string $userName,
        public readonly string $unsuspendedAt,
    ) {
    }

    public static function fromEvent(AccountUnsuspended $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            unsuspendedAt: self::now(),
        );
    }

    protected function subjectLine(): string
    {
        return 'Your Account Has Been Restored';
    }
}
