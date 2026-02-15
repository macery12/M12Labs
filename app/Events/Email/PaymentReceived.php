<?php

namespace Everest\Events\Email;

use Everest\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public float $amount,
        public string $currency,
        public string $paymentMethod,
        public ?string $invoiceId = null,
        public string $correlationId = '',
        public bool $isRenewal = false,
        public ?float $originalAmount = null,
        public ?float $discountAmount = null,
        public ?string $couponCode = null,
    ) {
    }
}
