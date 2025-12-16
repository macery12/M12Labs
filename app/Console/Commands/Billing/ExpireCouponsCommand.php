<?php

namespace Everest\Console\Commands\Billing;

use Illuminate\Console\Command;
use Everest\Models\Billing\Coupon;

class ExpireCouponsCommand extends Command
{
    protected $description = 'Automatically mark expired coupons as inactive.';

    protected $signature = 'p:billing:expire-coupons';

    /**
     * ExpireCouponsCommand constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Handle command execution.
     */
    public function handle()
    {
        $expiredCount = 0;

        // Find all active coupons that have passed their expiration date
        $coupons = Coupon::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($coupons as $coupon) {
            $coupon->update(['is_active' => false]);
            $expiredCount++;
        }

        if ($expiredCount > 0) {
            $this->info("Marked {$expiredCount} expired coupon(s) as inactive.");
        } else {
            $this->info('No expired coupons found.');
        }
    }
}
