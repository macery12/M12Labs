<?php

namespace Everest\Console\Commands\Billing;

use Illuminate\Console\Command;
use Everest\Models\Billing\Coupon;

class ExpireCouponsCommand extends Command
{
    protected $description = 'Automatically mark expired coupons as inactive.';

    protected $signature = 'p:billing:expire-coupons {--dry-run : Show which coupons would be expired without making changes}';

    /**
     * Handle command execution.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        // Find all active coupons that have passed their expiration date
        $query = Coupon::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());

        if ($dryRun) {
            $coupons = $query->get();
            $expiredCount = $coupons->count();

            if ($expiredCount > 0) {
                $this->info("Would mark {$expiredCount} expired coupon(s) as inactive:");
                foreach ($coupons as $coupon) {
                    $this->line("  - ID: {$coupon->id}, Code: {$coupon->code}, Expired: {$coupon->expires_at->format('Y-m-d H:i:s')}");
                }
            } else {
                $this->info('No expired coupons found.');
            }
        } else {
            $expiredCount = $query->update(['is_active' => false]);

            if ($expiredCount > 0) {
                $this->info("Marked {$expiredCount} expired coupon(s) as inactive.");
            } else {
                $this->info('No expired coupons found.');
            }
        }
    }
}
