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
     * @return array{server: Server, order: Order}
     */
    public function renew(Server $server, Product $product, ?int $couponId = null): array
    {
        // Verify that the server uses this product
        if ($server->billing_product_id !== $product->id) {
            throw new DisplayException('This server does not use this product.');
        }

        // Create an order record for the renewal
        $order = $this->orderService->create(
            null,
            $server->owner,
            $product,
            Order::STATUS_PENDING,
            Order::TYPE_REN,
            $couponId
        );

        // Unsuspend the server if it was suspended due to billing
        if ($server->isSuspended()) {
            $this->suspensionService->toggle($server, SuspensionService::ACTION_UNSUSPEND);
        }

        // Reset the renewal date based on product type (free or paid)
        $renewalDays = $product->getRenewalDays();
        $server->update([
            'renewal_date' => Carbon::now()->addDays($renewalDays)->toDateTimeString(),
        ]);

        // Mark order as processed
        $order->update([
            'status' => Order::STATUS_PROCESSED,
            'name' => $order->name . substr($server->uuid, 0, 8),
        ]);

        return ['server' => $server, 'order' => $order];
    }
}
