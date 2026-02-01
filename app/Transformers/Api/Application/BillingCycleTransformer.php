<?php

namespace Everest\Transformers\Api\Application;

use Everest\Models\Billing\BillingCycle;
use League\Fractal\Resource\Collection;
use Everest\Transformers\Api\Transformer;
use League\Fractal\Resource\NullResource;

class BillingCycleTransformer extends Transformer
{
    /**
     * List of resources that can be included.
     */
    protected array $availableIncludes = [
        'products',
    ];

    /**
     * {@inheritdoc}
     */
    public function getResourceName(): string
    {
        return BillingCycle::RESOURCE_NAME;
    }

    /**
     * Transform this model into a representation that can be consumed by a client.
     */
    public function transform(BillingCycle $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'durationDays' => $model->duration_days,
            'sortOrder' => $model->sort_order,
            'isActive' => $model->is_active,
            'created_at' => $model->created_at->toIso8601String(),
            'updated_at' => $model->updated_at ? $model->updated_at->toIso8601String() : null,
        ];
    }

    /**
     * Return a generic array with product information.
     */
    public function includeProducts(BillingCycle $cycle): Collection|NullResource
    {
        return $this->collection($cycle->products, new ProductTransformer());
    }
}
