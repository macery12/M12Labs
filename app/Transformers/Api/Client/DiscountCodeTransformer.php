<?php

namespace Everest\Transformers\Api\Client;

use Everest\Models\Billing\DiscountCode;
use Everest\Transformers\Api\Transformer;

class DiscountCodeTransformer extends Transformer
{
    public function getResourceName(): string
    {
        return DiscountCode::RESOURCE_NAME;
    }

    /**
     * Transform this model into a representation that can be consumed by a client.
     */
    public function transform(DiscountCode $model): array
    {
        return [
            'code' => $model->code,
            'description' => $model->description,
            'type' => $model->type,
            'value' => $model->value,
        ];
    }
}
