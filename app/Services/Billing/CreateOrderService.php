<?php

namespace Everest\Services\Billing;

use Everest\Models\User;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;

class CreateOrderService
{
    /**
     * Process the creation of an order.
     */
    public function create(?string $intent, User $user, Product $product, ?string $status, ?string $type, ?int $couponId = null, ?int $eggId = null, array $additionalData = []): Order
    {
        $order = new Order();
        $uuid = uuid_create();

        // Get billing days from additional data or default to 30
        $billingDays = $additionalData['billing_days'] ?? 30;

        // Get node ID for location-based pricing
        $nodeId = $additionalData['node_id'] ?? null;

        // Calculate price based on billing cycle and node
        $priceInfo = $product->calculatePrice($billingDays, $nodeId);
        $subtotal = $priceInfo['price'];
        $multiplierUsed = $priceInfo['multiplier'];
        $nodeMultiplierUsed = $priceInfo['node_multiplier'];

        $discount = 0;
        $total = $subtotal;

        // Apply coupon if provided
        if ($couponId) {
            $coupon = \Everest\Models\Billing\Coupon::find($couponId);
            if ($coupon) {
                $discount = $coupon->calculateDiscount($subtotal);
                $total = max(0, $subtotal - $discount);
            }
        }

        $order->name = $additionalData['name'] ?? $uuid;
        $order->payment_intent_id = $intent ?? 'free-' . substr(uuid_create(), 0, 16);
        $order->user_id = $user->id;
        $order->description = substr($uuid, 0, 8) . ' - Order for ' . $product->name . ' by ' . $user->email;
        $order->subtotal = $subtotal;
        $order->discount = $discount;
        $order->total = $total;
        $order->billing_days = $billingDays;
        $order->final_price = $total;
        $order->multiplier_used = $multiplierUsed;
        $order->node_multiplier_used = $nodeMultiplierUsed;
        $order->status = $status ?? Order::STATUS_EXPIRED;
        $order->product_id = $product->id;
        $order->coupon_id = $couponId;
        $order->egg_id = $eggId;
        $order->node_id = $nodeId;
        $order->server_id = $additionalData['server_id'] ?? null;
        $order->variables = $additionalData['variables'] ?? null;
        $order->domain_payload = $additionalData['domain_payload'] ?? null;
        $order->type = $type;
        $order->payment_processor = $additionalData['payment_processor'] ?? 'stripe';
        $order->mollie_payment_id = $additionalData['mollie_payment_id'] ?? null;
        $order->paypal_order_id = $additionalData['paypal_order_id'] ?? null;
        $order->payment_token = $additionalData['payment_token'] ?? null;

        $order->saveOrFail();

        return $order;
    }
}
