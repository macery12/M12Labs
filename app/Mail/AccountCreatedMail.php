<?php

namespace Everest\Mail;

use Everest\Events\Email\AccountCreated;

class AccountCreatedMail extends PanelMail
{
    public const KEY = 'auth.account_created';

    public function __construct(
        public readonly string $userName,
        public readonly string $userEmail,
        public readonly string $loginUrl,
    ) {
    }

    public static function fromEvent(AccountCreated $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            userEmail: $event->user->email,
            loginUrl: url('/auth/login'),
        );
    }

    protected function subjectLine(): string
    {
        return 'Welcome to Your Account';
    }
}
