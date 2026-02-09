<?php

namespace Everest\Services\Billing;

use Carbon\Carbon;
use Everest\Models\Server;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Services\Servers\SuspensionService;

class ServerRenewalService
{
    public function __construct(
        private SuspensionService $suspensionService,
        private CreateOrderService $orderService,
    ) {
    }

    /**
     * Renew a server by extending its renewal date.
     * This handles both free and paid server renewals.
     * 
     * For free servers: Resets renewal date to configured days from now
     * For paid servers: Adds configured days to existing renewal date (extends the time)
     * 
     * If server is past due but still within grace period, the renewal days are
     * reduced by the number of past due days to prevent users from getting free time.
     * 
     * @return array{server: Server, order: Order}
     */
    public function renew(Server $server, Product $product, ?int $couponId = null, int $billingDays = 30): array
    {
        // Verify that the server uses this product
        if ($server->billing_product_id !== $product->id) {
            throw new DisplayException('This server does not use this product.');
        }

        // Create an order record for the renewal
        $order = $this->orderService->create(
            null,
            $server->user,
            $product,
            Order::STATUS_PENDING,
            Order::TYPE_REN,
            $couponId,
            null,
            ['billing_days' => $billingDays]
        );

        // Unsuspend the server if it was suspended due to billing
        if ($server->isSuspended()) {
            $this->suspensionService->toggle($server, SuspensionService::ACTION_UNSUSPEND);
        }

        // Use the billing days provided or fall back to product's renewal days
        $renewalDays = $billingDays > 0 ? $billingDays : $product->getRenewalDays();
        
        // Calculate past due days if server is overdue
        $pastDueDays = 0;
        if ($server->renewal_date && $server->renewal_date->isPast()) {
            $pastDueDays = Carbon::now()->diffInDays($server->renewal_date);
            
            // Get the suspension threshold (grace period) for this billing cycle
            $serverBillingDays = $server->billing_days > 0 ? $server->billing_days : $billingDays;
            $suspensionThreshold = $product->getSuspensionThresholdForBillingCycle($serverBillingDays);
            
            // Only adjust renewal days if server is still within grace period (able to be renewed)
            // If past the grace period, they shouldn't be able to renew anyway
            if ($pastDueDays <= $suspensionThreshold) {
                // Subtract past due days from renewal days, but ensure we give at least 1 day
                $renewalDays = max(1, $renewalDays - $pastDueDays);
            }
        }
        
        if ($product->isFree()) {
            // Free servers: Reset renewal date to configured days from now
            $newRenewalDate = Carbon::now()->addDays($renewalDays)->toDateTimeString();
        } else {
            // Paid servers: Add configured days to existing renewal date to extend the time
            // Use copy() to avoid mutating the original Carbon instance
            // If renewal_date is null or in the past, start from now instead
            $baseDate = $server->renewal_date && $server->renewal_date->isFuture()
                ? $server->renewal_date->copy()
                : Carbon::now();
            $newRenewalDate = $baseDate->addDays($renewalDays)->toDateTimeString();
        }
        
        $server->update([
            'renewal_date' => $newRenewalDate,
        ]);

        // Mark order as processed
        $order->update([
            'status' => Order::STATUS_PROCESSED,
            'name' => $order->name . substr($server->uuid, 0, 8),
        ]);

        return ['server' => $server, 'order' => $order];
    }
}
