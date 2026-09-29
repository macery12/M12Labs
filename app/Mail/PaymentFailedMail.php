<?php

namespace Everest\Mail;

use Everest\Events\Email\PaymentFailed;

class PaymentFailedMail extends PanelMail
{
    public const KEY = 'billing.payment_failed';

    public function __construct(
        public readonly string $userName,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $reason,
        public readonly string $invoiceId,
        public readonly string $retryUrl,
        public readonly string $paymentMethod,
        public readonly bool $isRenewal,
    ) {
    }

    public static function fromEvent(PaymentFailed $event): self
    {
        return new self(
            userName: self::displayName($event->user),
            amount: number_format($event->amount, 2),
            currency: strtoupper($event->currency),
            reason: $event->reason,
            invoiceId: $event->invoiceId ?? 'N/A',
            retryUrl: url('/billing'),
            paymentMethod: $event->paymentMethod,
            isRenewal: $event->isRenewal,
        );
    }

    protected function subjectLine(): string
    {
        return 'Payment Failed - Action Required';
    }
}
