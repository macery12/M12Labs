<?php

namespace Everest\Console\Commands\Billing;

use Everest\Models\User;
use Illuminate\Console\Command;
use Everest\Models\Billing\Order;

class CleanupOrdersCommand extends Command
{
    protected $description = 'An automated task to delete and edit billing orders.';

    protected $signature = 'p:billing:cleanup-orders';

    /**
     * CleanupOrdersCommand constructor.
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
        $sevenDaysAgo = now()->subDays(7);

        // Process orders in chunks to avoid memory issues
        Order::whereIn('status', ['pending', 'expired'])->chunk(100, function ($orders) use ($sevenDaysAgo) {
            foreach ($orders as $order) {
                $user = User::find($order->user_id) ?? null;

                if (!$user) {
                    $order->delete();
                    continue;
                }

                try {
                    switch ($order->status) {
                        case 'pending':
                            // Only expire pending orders that are older than 7 days
                            if ($order->created_at->lte($sevenDaysAgo)) {
                                $order->forceFill(['status' => Order::STATUS_EXPIRED])->save();
                            }
                            break;
                        case 'expired':
                            // Only delete expired orders that have been expired for more than 7 days
                            if ($order->updated_at->lte($sevenDaysAgo)) {
                                $order->delete();
                            }
                            break;
                    }
                } catch (\Exception $ex) {
                    // handle quietly, no need to log this
                }
            }
        });
    }
}
