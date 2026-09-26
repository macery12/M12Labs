<?php

namespace Everest\Transformers\Api\Client;

use Everest\Models\Billing\Product;
use Everest\Transformers\Api\Transformer;

class ProductTransformer extends Transformer
{
    public function getResourceName(): string
    {
        return Product::RESOURCE_NAME;
    }

    /**
     * Transform this model into a representation that can be consumed by a client.
     */
    public function transform(Product $model): array
    {
        // A product outlives its category when the category is deleted. It
        // must still render (a server on that plan shows it on its billing
        // page), but it can't be ordered: the category supplies the eggs.
        // This used to 500 the whole storefront when such a plan was pinned.
        $category = $model->category;

        return [
            'id' => $model->id,
            'name' => $model->name,
            'icon' => $model->icon,
            'price' => $model->price,
            'description' => $model->description,
            'egg_id' => $category?->getDefaultEggId(),
            'allowed_eggs' => $category?->getAllowedEggs() ?? [],
            'allow_egg_changes' => (bool) $category?->allow_egg_changes,
            'available' => $category !== null,
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
