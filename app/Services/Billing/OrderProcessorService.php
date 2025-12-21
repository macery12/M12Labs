<?php

namespace Everest\Services\Billing;

use Everest\Models\User;
use Everest\Models\Server;
use Illuminate\Http\Request;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\CouponUsage;

/**
 * Unified order processing service for billing operations.
 * 
 * This service consolidates order creation and processing logic that was previously
 * duplicated across the old FreeProductController and PaymentController (now replaced by CheckoutController).
 * 
 * Responsibilities:
 * - Create orders with proper metadata
 * - Record coupon usage
 * - Coordinate server creation and renewal
 * - Update order status after processing
 */
class OrderProcessorService
{
    public function __construct(
        private CreateOrderService $orderService,
        private CreateServerService $serverCreationService,
        private ServerRenewalService $renewalService,
    ) {
    }

    /**
     * Create a new server order and process it.
     * 
     * This method handles both free and paid server creation.
     * 
     * @param Request $request The HTTP request
     * @param User $user The user creating the order
     * @param Product $product The product being purchased
     * @param int $nodeId The node ID to deploy to
     * @param int $eggId The validated egg ID
     * @param int|null $couponId The coupon ID (optional)
     * @param array $variables Custom environment variables (optional)
     * @param string|null $paymentIntentId The Stripe payment intent ID (for paid orders)
     * @return array{server: Server, order: Order}
     */
    public function createServerOrder(
        Request $request,
        User $user,
        Product $product,
        int $nodeId,
        int $eggId,
        ?int $couponId = null,
        array $variables = [],
        ?string $paymentIntentId = null
    ): array {
        // Create the order record
        $order = $this->orderService->create(
            $paymentIntentId,
            $user,
            $product,
            Order::STATUS_PENDING,
            Order::TYPE_NEW,
            $couponId,
            $eggId
        );

        // Create the server
        $server = $this->serverCreationService->processFree(
            $request,
            $product,
            $nodeId,
            $order,
            $variables
        );

        // Record coupon usage if applicable
        if ($couponId) {
            $this->recordCouponUsage($couponId, $user->id, $order->id);
        }

        // Update order status and name
        $order->update([
            'status' => Order::STATUS_PROCESSED,
            'name' => $order->name . substr($server->uuid, 0, 8),
        ]);

        return ['server' => $server, 'order' => $order];
    }

    /**
     * Process a server renewal.
     * 
     * This method handles both free and paid server renewals.
     * 
     * @param Server $server The server to renew
     * @param Product $product The product to renew with
     * @param int|null $couponId The coupon ID (optional)
     * @return array{server: Server, order: Order}
     */
    public function processRenewal(
        Server $server,
        Product $product,
        ?int $couponId = null
    ): array {
        // Use the unified renewal service
        $result = $this->renewalService->renew($server, $product, $couponId);

        // Record coupon usage if applicable
        if ($couponId) {
            $this->recordCouponUsage($couponId, $server->user->id, $result['order']->id);
        }

        return $result;
    }

    /**
     * Record a coupon usage.
     * 
     * @param int $couponId The coupon ID
     * @param int $userId The user ID
     * @param int $orderId The order ID
     */
    private function recordCouponUsage(int $couponId, int $userId, int $orderId): void
    {
        CouponUsage::create([
            'coupon_id' => $couponId,
            'user_id' => $userId,
            'order_id' => $orderId,
            'used_at' => now(),
        ]);
    }

    /**
     * Update an order's name after server creation.
     * 
     * @param Order $order The order to update
     * @param Server $server The created server
     */
    public function finalizeOrderName(Order $order, Server $server): void
    {
        $order->update([
            'name' => $order->name . substr($server->uuid, 0, 8),
        ]);
    }
}
