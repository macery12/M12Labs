<?php

namespace Everest\Services\Email;

use Everest\Models\ResendQuota;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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

    public function syncFromProvider(?int $dailyUsed, ?int $monthlyUsed, array $rateLimit = []): void
    {
        try {
            $plan = $this->plans->activePlan();
            $quota = ResendQuota::singleton();
            $quota->resetIfNeeded();

            if ($monthlyUsed !== null) {
                $quota->monthly_sent = $monthlyUsed;
            }
            if ($dailyUsed !== null) {
                $quota->daily_sent = $dailyUsed;
            }
            $quota->save();

            Cache::put($this->usageSourceCacheKey(), [
                'source' => 'provider',
                'synced_at' => now()->toIso8601String(),
            ], 3600);

            if (!empty($rateLimit)) {
                Cache::put($this->rateLimitCacheKey(), [
                    'limit' => $rateLimit['limit'] ?? null,
                    'remaining' => $rateLimit['remaining'] ?? null,
                    'reset' => $rateLimit['reset'] ?? null,
                    'retry_after' => $rateLimit['retry_after'] ?? null,
                    'updated_at' => now()->toIso8601String(),
                ], 900);
            }
        } catch (\Throwable $e) {
            Log::debug('ResendQuotaService: failed to sync provider usage', ['error' => $e->getMessage()]);
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
                'usage' => array_merge($usage, [
                    'source' => Cache::get($this->usageSourceCacheKey())['source'] ?? 'internal',
                    'synced_at' => Cache::get($this->usageSourceCacheKey())['synced_at'] ?? null,
                ]),
                'rate_limit' => Cache::get($this->rateLimitCacheKey(), null),
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
                    'source' => 'internal',
                    'synced_at' => null,
                ],
                'rate_limit' => Cache::get($this->rateLimitCacheKey(), null),
            ];
        }
    }

    private function usageSourceCacheKey(): string
    {
        return 'resend_usage_source';
    }

    private function rateLimitCacheKey(): string
    {
        return 'resend_rate_limit';
    }
}
