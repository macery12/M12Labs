<?php

namespace Everest\Events\Email;

use Everest\Models\User;
use Everest\Models\Server;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerRenewalNotice
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user,
        public Server $server,
        public string $renewalUrl,
        public string $expiresAt,
        public string $suspensionTime,
        public float $renewalAmount,
        public string $currency,
        public int $billingDays = 30,
        public string $correlationId = '',
    ) {
    }
}
