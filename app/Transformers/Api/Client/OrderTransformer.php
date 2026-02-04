<?php

namespace Everest\Transformers\Api\Client;

use Everest\Models\Billing\Order;
use Everest\Transformers\Api\Transformer;

class OrderTransformer extends Transformer
{
    /**
     * {@inheritdoc}
     */
    public function getResourceName(): string
    {
        return Order::RESOURCE_NAME;
    }

    /**
     * Transform this model into a representation that can be consumed by a client.
     */
    public function transform(Order $model): array
    {
        $server = $model->server;
        
        return [
            'id' => $model->id,
            'name' => $model->name,
            'description' => $model->description,
            'total' => $model->total,
            'status' => $model->status,
            'product_id' => $model->product_id,
            'type' => $model->type ?? '?',
            'payment_processor' => $model->payment_processor ?? 'stripe',
            'payment_intent_id' => $model->payment_intent_id,
            'mollie_payment_id' => $model->mollie_payment_id,
            'paypal_order_id' => $model->paypal_order_id,
            'paypal_capture_id' => $model->paypal_capture_id,
            'paypal_payer_id' => $model->paypal_payer_id,
            'paypal_payer_email' => $model->paypal_payer_email,
            'paypal_status' => $model->paypal_status,
            'paypal_amount' => $model->paypal_amount,
            'paypal_currency' => $model->paypal_currency,
            'paypal_captured_at' => $model->paypal_captured_at ? $model->paypal_captured_at->toIso8601String() : null,
            'server_id' => $model->server_id,
            'server_uuid' => $server ? $server->uuid : null,
            'server_name' => $server ? $server->name : null,
            'created_at' => $model->created_at->toIso8601String(),
            'updated_at' => $model->updated_at->toIso8601String(),
        ];
    }
}
