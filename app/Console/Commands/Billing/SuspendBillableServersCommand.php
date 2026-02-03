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

        foreach (Server::whereNotNull('billing_product_id')->get() as $server) {
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

                // Use product method to get suspension threshold
                $suspensionThreshold = $product->getSuspensionThresholdDays();

                // Only suspend if overdue by more than the threshold
                if ($daysOverdue > $suspensionThreshold && !$server->isSuspended()) {
                    $this->info("suspending server {$server->id}, overdue by {$daysOverdue} day(s)");
                    $this->suspend->toggle($server, 'suspend');
                }
            }
        }
    }
}
