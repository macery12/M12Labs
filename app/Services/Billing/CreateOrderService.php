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
    public function create(?string $transaction_id, User $user, Product $product, ?string $status, ?bool $is_new_order): Order
    {
        $order = new Order();
        $uuid = uuid_create();

        $order->name = $uuid;
        $order->transaction_id = $transaction_id;
        $order->user_id = $user->id;
        $order->description = $product->name . ' with ID ' . substr($uuid, 0, 8);
        $order->total = $product->price ?? 0;
        $order->status = $status ?? Order::STATUS_EXPIRED;
        $order->product_id = $product->id;
        $order->type = $is_new_order ? Order::TYPE_NEW : Order::TYPE_RENEWAL;

        $order->saveOrFail();

        return $order;
    }
}
