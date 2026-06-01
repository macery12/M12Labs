<?php

namespace Everest\Console\Commands\Billing;

use Everest\Models\Server;
use Illuminate\Console\Command;
use Everest\Services\Servers\SuspensionService;

class SuspendBillableServersCommand extends Command
{
    protected $description = 'An automated task to suspend billable servers with past renewal dates.';

    protected $signature = 'p:billing:suspend-billable-servers';

    /**
     * SuspendBillableServersCommand constructor.
     */
    public function __construct(private SuspensionService $suspend)
    {
        parent::__construct();
    }

    /**
     * Handle command execution.
     */
    public function handle()
    {
        $now = now();

        Server::whereNotNull('billing_product_id')
            ->with('product')
            ->chunk(200, function ($servers) use ($now) {
                foreach ($servers as $server) {
                    $renewalDate = $server->renewal_date;

                    if ($renewalDate === null) {
                        continue;
                    }

                    if ($renewalDate->isPast()) {
                        $daysOverdue = $renewalDate->diffInDays($now);

                        // Get the product to determine suspension threshold
                        $product = $server->product;

                        if (!$product) {
                            continue;
                        }

                        // Get the server's billing cycle length
                        $billingDays = $server->billing_days;

                        if (!$billingDays || $billingDays <= 0) {
                            // Fall back to default if billing_days is not set
                            $billingDays = config('modules.billing.renewal.default_billing_days', 30);
                        }

                        // Calculate suspension threshold based on the server's billing cycle
                        // This ensures longer billing cycles get proportionally longer grace periods
                        $suspensionThreshold = $product->getSuspensionThresholdForBillingCycle($billingDays);

                        // Only suspend if overdue by more than the threshold
                        if ($daysOverdue > $suspensionThreshold && !$server->isSuspended()) {
                            $this->info("suspending server {$server->id}, overdue by {$daysOverdue} day(s) (threshold: {$suspensionThreshold} days for {$billingDays}-day cycle)");

                            // Use the exact same suspension logic as the manual suspend button
                            // This ensures servers can be manually unsuspended via admin panel
                            $this->suspend->toggle($server, SuspensionService::ACTION_SUSPEND);
                        }
                    }
                }
            });
    }
}
