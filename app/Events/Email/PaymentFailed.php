<?php

namespace Everest\Events\Email;

use Everest\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public float $amount,
        public string $currency,
        public string $reason,
        public ?string $invoiceId = null,
        public string $correlationId = '',
    ) {
    }
}
