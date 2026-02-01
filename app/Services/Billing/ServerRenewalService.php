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
     * @param Server $server The server to renew
     * @param Product $product The product to renew with
     * @param int|null $couponId The coupon ID (optional)
     * @param int|null $billingCycleId The billing cycle ID (optional, defaults to server's current cycle)
     * @return array{server: Server, order: Order}
     */
    public function renew(Server $server, Product $product, ?int $couponId = null, ?int $billingCycleId = null): array
    {
        // Verify that the server uses this product
        if ($server->billing_product_id !== $product->id) {
            throw new DisplayException('This server does not use this product.');
        }

        // Use provided billing cycle or server's current cycle
        $cycleId = $billingCycleId ?? $server->billing_cycle_id;

        // Create an order record for the renewal
        $order = $this->orderService->create(
            null,
            $server->user,
            $product,
            Order::STATUS_PENDING,
            Order::TYPE_REN,
            $couponId,
            null, // No egg change on renewal
            $cycleId
        );

        // Unsuspend the server if it was suspended due to billing
        if ($server->isSuspended()) {
            $this->suspensionService->toggle($server, SuspensionService::ACTION_UNSUSPEND);
        }

        // Calculate renewal date based on billing cycle
        $renewalDays = $cycleId ? $product->getRenewalDays($cycleId) : $product->getRenewalDays();
        
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
            'billing_cycle_id' => $cycleId, // Update to new cycle if changed
        ]);

        // Mark order as processed
        $order->update([
            'status' => Order::STATUS_PROCESSED,
            'name' => $order->name . substr($server->uuid, 0, 8),
        ]);

        return ['server' => $server, 'order' => $order];
    }
}
