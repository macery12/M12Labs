<?php

namespace Everest\Models;

use Illuminate\Support\Facades\DB;

/**
 * Tracks Resend provider quota consumption (daily / monthly).
 *
 * @property int $id
 * @property int $daily_sent
 * @property int $monthly_sent
 * @property \Illuminate\Support\Carbon|null $day_reset_at
 * @property \Illuminate\Support\Carbon|null $month_reset_at
 */
class ResendQuota extends Model
{
    protected $table = 'resend_quotas';

    protected $fillable = [
        'daily_sent',
        'monthly_sent',
        'day_reset_at',
        'month_reset_at',
    ];

    protected $casts = [
        'daily_sent' => 'integer',
        'monthly_sent' => 'integer',
        'day_reset_at' => 'date',
        'month_reset_at' => 'date',
    ];

    public static function singleton(): self
    {
        return static::query()->firstOrCreate([], [
            'daily_sent' => 0,
            'monthly_sent' => 0,
            'day_reset_at' => now()->startOfDay(),
            'month_reset_at' => now()->startOfMonth(),
        ]);
    }

    public function resetIfNeeded(): void
    {
        $now = now();

        if (!$this->day_reset_at || $this->day_reset_at->lt($now->copy()->startOfDay())) {
            $this->daily_sent = 0;
            $this->day_reset_at = $now->copy()->startOfDay();
        }

        if (!$this->month_reset_at || $this->month_reset_at->lt($now->copy()->startOfMonth())) {
            $this->monthly_sent = 0;
            $this->month_reset_at = $now->copy()->startOfMonth();
        }

        $this->save();
    }

    public function reserve(array $plan, int $count = 1): array
    {
        return DB::transaction(function () use ($plan, $count) {
            /** @var self $quota */
            $quota = self::query()->lockForUpdate()->firstOrCreate([], [
                'daily_sent' => 0,
                'monthly_sent' => 0,
                'day_reset_at' => now()->startOfDay(),
                'month_reset_at' => now()->startOfMonth(),
            ]);

            $quota->resetIfNeeded();

            $dailyLimit = $plan['enforce_daily'] && $plan['daily_limit'] !== null ? $plan['daily_limit'] : null;
            $monthlyLimit = $plan['enforce_monthly'] && $plan['monthly_limit'] !== null ? $plan['monthly_limit'] : null;

            $dailyExceeded = $dailyLimit !== null && ($quota->daily_sent + $count > $dailyLimit);
            $monthlyExceeded = $monthlyLimit !== null && ($quota->monthly_sent + $count > $monthlyLimit);

            if ($dailyExceeded || $monthlyExceeded) {
                return [
                    'allowed' => false,
                    'reason' => $dailyExceeded ? 'resend_daily_quota_reached' : 'resend_monthly_quota_reached',
                    'scheduled_at' => $quota->nextAvailableTime($dailyLimit, $monthlyLimit),
                ];
            }

            $quota->monthly_sent += $count;
            if ($dailyLimit !== null) {
                $quota->daily_sent += $count;
            }

            $quota->save();

            return [
                'allowed' => true,
                'quota' => $quota->fresh(),
            ];
        });
    }

    public function usage(array $plan): array
    {
        $dailyLimit = $plan['enforce_daily'] && $plan['daily_limit'] !== null ? $plan['daily_limit'] : null;
        $monthlyLimit = $plan['enforce_monthly'] && $plan['monthly_limit'] !== null ? $plan['monthly_limit'] : null;

        $this->resetIfNeeded();

        return [
            'daily_sent' => $this->daily_sent,
            'monthly_sent' => $this->monthly_sent,
            'daily_limit' => $dailyLimit,
            'monthly_limit' => $monthlyLimit,
            'daily_remaining' => $dailyLimit !== null ? max(0, $dailyLimit - $this->daily_sent) : null,
            'monthly_remaining' => $monthlyLimit !== null ? max(0, $monthlyLimit - $this->monthly_sent) : null,
            'next_daily_reset' => $this->day_reset_at?->startOfDay(),
            'next_monthly_reset' => $this->month_reset_at?->startOfMonth(),
        ];
    }

    private function nextAvailableTime(?int $dailyLimit, ?int $monthlyLimit): \Carbon\Carbon
    {
        $now = now();

        if ($monthlyLimit !== null && $this->monthly_sent >= $monthlyLimit) {
            return $now->copy()->addMonth()->startOfMonth();
        }

        if ($dailyLimit !== null && $this->daily_sent >= $dailyLimit) {
            return $now->copy()->addDay()->startOfDay();
        }

        return $now;
    }
}
