<?php

namespace Everest\Services\Email;

use Everest\Models\ResendQuota;
use Illuminate\Support\Facades\Log;

class ResendQuotaService
{
    public function __construct(private readonly ResendPlanResolver $plans)
    {
    }

    public function reserve(int $count = 1): ResendQuotaReservation
    {
        try {
            $plan = $this->plans->activePlan();
            $result = ResendQuota::singleton()->reserve($plan, $count);
            $usage = $result['quota']?->usage($plan);
            if ($usage) {
                $usage['next_daily_reset'] = $usage['next_daily_reset']?->toIso8601String();
                $usage['next_monthly_reset'] = $usage['next_monthly_reset']?->toIso8601String();
            }

            return new ResendQuotaReservation(
                allowed: $result['allowed'],
                reason: $result['reason'] ?? null,
                scheduledAt: $result['scheduled_at'] ?? null,
                plan: $plan,
                usage: $usage
            );
        } catch (\Throwable $e) {
            Log::warning('ResendQuotaService: Failed to reserve quota', [
                'error' => $e->getMessage(),
            ]);

            return new ResendQuotaReservation(true, plan: $this->plans->activePlan());
        }
    }

    public function usage(): array
    {
        try {
            $plan = $this->plans->activePlan();
            $usage = ResendQuota::singleton()->usage($plan);
            $usage['next_daily_reset'] = $usage['next_daily_reset']?->toIso8601String();
            $usage['next_monthly_reset'] = $usage['next_monthly_reset']?->toIso8601String();

            return [
                'plan' => $plan,
                'usage' => $usage,
            ];
        } catch (\Throwable $e) {
            Log::debug('ResendQuotaService: Failed to read usage (likely before migrations)', [
                'error' => $e->getMessage(),
            ]);

            $plan = $this->plans->activePlan();

            return [
                'plan' => $plan,
                'usage' => [
                    'daily_sent' => 0,
                    'monthly_sent' => 0,
                    'daily_limit' => $plan['enforce_daily'] ? $plan['daily_limit'] : null,
                    'monthly_limit' => $plan['enforce_monthly'] ? $plan['monthly_limit'] : null,
                    'daily_remaining' => $plan['enforce_daily'] ? $plan['daily_limit'] : null,
                    'monthly_remaining' => $plan['enforce_monthly'] ? $plan['monthly_limit'] : null,
                    'next_daily_reset' => null,
                    'next_monthly_reset' => null,
                ],
            ];
        }
    }
}
