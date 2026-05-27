<?php

namespace Everest\Transformers\Api\Application;

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
        $paymentProcessor = $this->resolvePaymentProcessor($model);

        return [
            'id' => $model->id,
            'name' => $model->name,
            'user_id' => $model->user_id,
            'description' => $model->description,
            'total' => $model->total,
            'status' => $model->status,
            'product_id' => $model->product_id,
            'type' => $model->type ?? '?',
            'payment_processor' => $paymentProcessor,
            'transaction' => $model->transaction ? [
                'external_id' => $model->transaction->external_id,
                'capture_id'  => $model->transaction->capture_id,
                'status'      => $model->transaction->status,
                'amount'      => $model->transaction->amount,
                'currency'    => $model->transaction->currency,
                'payer_id'    => $model->transaction->payer_id,
                'payer_email' => $model->transaction->payer_email,
                'captured_at' => $model->transaction->captured_at?->toIso8601String(),
            ] : null,
            'threat_index' => $model->threat_index,
            'server_id' => $model->server_id,
            'server_uuid' => $server ? $server->uuid : null,
            'server_name' => $server ? $server->name : null,
            'created_at' => $model->created_at->toIso8601String(),
            'updated_at' => $model->updated_at->toIso8601String() ? $model->updated_at->toIso8601String() : null,
        ];
    }

    private function resolvePaymentProcessor(Order $model): string
    {
        return strtolower((string) ($model->payment_processor ?? 'stripe'));
    }
}
