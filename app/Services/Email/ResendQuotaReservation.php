<?php

namespace Everest\Services\Email;

use Carbon\Carbon;

class ResendQuotaReservation
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
        public readonly ?Carbon $scheduledAt = null,
        public readonly ?array $plan = null,
        public readonly ?array $usage = null,
    ) {
    }
}
