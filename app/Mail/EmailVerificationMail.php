<?php

namespace Everest\Mail;

use Everest\Events\Email\EmailVerificationRequested;

class EmailVerificationMail extends PanelMail
{
    public const KEY = 'auth.email_verification';

    public function __construct(
        public readonly string $userName,
        public readonly string $verificationUrl,
        public readonly string $expiresIn,
    ) {
    }

    public static function fromEvent(EmailVerificationRequested $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            verificationUrl: $event->verificationUrl,
            expiresIn: '60 minutes',
        );
    }

    protected function subjectLine(): string
    {
        return 'Verify Your Email Address';
    }
}
