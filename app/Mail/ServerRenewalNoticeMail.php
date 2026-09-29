<?php

namespace Everest\Mail;

use Everest\Events\Email\ServerRenewalNotice;

class ServerRenewalNoticeMail extends PanelMail
{
    public const KEY = 'billing.server_renewal_notice';

    public function __construct(
        public readonly string $userName,
        public readonly string $serverName,
        public readonly string $renewalUrl,
        public readonly string $renewalDate,
        public readonly string $suspensionTime,
        public readonly string $renewalAmount,
        public readonly string $currency,
        public readonly int $billingDays,
        public readonly string $billingCycle,
    ) {
    }

    public static function fromEvent(ServerRenewalNotice $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            serverName: $event->server->name,
            renewalUrl: $event->renewalUrl,
            renewalDate: $event->renewalDate,
            suspensionTime: $event->suspensionTime,
            renewalAmount: number_format($event->renewalAmount, 2),
            currency: strtoupper($event->currency),
            billingDays: $event->billingDays,
            billingCycle: self::billingCycle($event->billingDays),
        );
    }

    protected function subjectLine(): string
    {
        return 'Server Renewal Notice - Action Required';
    }
}
