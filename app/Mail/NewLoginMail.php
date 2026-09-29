<?php

namespace Everest\Mail;

use Everest\Events\Email\NewLoginDetected;

class NewLoginMail extends PanelMail
{
    public const KEY = 'auth.new_login';

    public function __construct(
        public readonly string $userName,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly string $location,
        public readonly string $loginTime,
    ) {
    }

    public static function fromEvent(NewLoginDetected $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            ipAddress: $event->ipAddress,
            userAgent: $event->userAgent,
            location: $event->location ?? 'Unknown',
            loginTime: \DateTimeImmutable::createFromInterface($event->loginAt)
                ->setTimezone(new \DateTimeZone((string) config('app.timezone')))
                ->format('F j, Y g:i A T'),
        );
    }

    protected function subjectLine(): string
    {
        return 'New Login Detected';
    }
}
