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
    public function create(?string $intent, User $user, Product $product, ?string $status, ?string $type, ?int $couponId = null, ?int $eggId = null, ?int $billingCycleId = null, array $additionalData = []): Order
    {
        $order = new Order();
        $uuid = uuid_create();

        // Always use product's price - billing cycle only affects duration
        $subtotal = $product->price ?? 0;
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
        $order->status = $status ?? Order::STATUS_EXPIRED;
        $order->product_id = $product->id;
        $order->billing_cycle_id = $billingCycleId;
        $order->coupon_id = $couponId;
        $order->egg_id = $eggId;
        $order->node_id = $additionalData['node_id'] ?? null;
        $order->server_id = $additionalData['server_id'] ?? null;
        $order->variables = $additionalData['variables'] ?? null;
        $order->type = $type;
        $order->payment_processor = $additionalData['payment_processor'] ?? 'stripe';
        $order->mollie_payment_id = $additionalData['mollie_payment_id'] ?? null;
        $order->paypal_order_id = $additionalData['paypal_order_id'] ?? null;
        $order->payment_token = $additionalData['payment_token'] ?? null;

        $order->saveOrFail();

        return $order;
    }
}
