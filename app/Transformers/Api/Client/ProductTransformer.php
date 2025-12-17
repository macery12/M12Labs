<?php

namespace Everest\Transformers\Api\Client;

use Everest\Models\Billing\Product;
use Everest\Transformers\Api\Transformer;

class ProductTransformer extends Transformer
{
    /**
     * {@inheritdoc}
     */
    public function getResourceName(): string
    {
        return Product::RESOURCE_NAME;
    }

    /**
     * Transform this model into a representation that can be consumed by a client.
     */
    public function transform(Product $model): array
    {
        // Use the first allowed egg as the default for backward compatibility
        $allowedEggs = $model->category->allowed_eggs ?? [$model->category->egg_id];
        $defaultEggId = is_array($allowedEggs) && count($allowedEggs) > 0 
            ? $allowedEggs[0] 
            : $model->category->egg_id;

        return [
            'id' => $model->id,
            'name' => $model->name,
            'icon' => $model->icon,
            'price' => $model->price,
            'description' => $model->description,
            'egg_id' => $defaultEggId,
            'allowed_eggs' => $allowedEggs,
            'limits' => [
                'cpu' => $model->cpu_limit,
                'memory' => $model->memory_limit,
                'disk' => $model->disk_limit,
                'backup' => $model->backup_limit,
                'database' => $model->database_limit,
                'allocation' => $model->allocation_limit,
            ],
        ];
    }
}
