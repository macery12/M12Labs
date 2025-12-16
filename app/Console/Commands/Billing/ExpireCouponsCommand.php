<?php

namespace Everest\Console\Commands\Billing;

use Illuminate\Console\Command;
use Everest\Models\Billing\Coupon;

class ExpireCouponsCommand extends Command
{
    protected $description = 'Automatically mark expired coupons as inactive.';

    protected $signature = 'p:billing:expire-coupons';

    /**
     * Handle command execution.
     */
    public function handle()
    {
        // Find and update all active coupons that have passed their expiration date
        $expiredCount = Coupon::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['is_active' => false]);

        if ($expiredCount > 0) {
            $this->info("Marked {$expiredCount} expired coupon(s) as inactive.");
        } else {
            $this->info('No expired coupons found.');
        }
    }
}
