<?php

namespace Everest\Transformers\Api\Client;

use Everest\Models\Billing\Category;
use Everest\Transformers\Api\Transformer;

class CategoryTransformer extends Transformer
{
    /**
     * {@inheritdoc}
     */
    public function getResourceName(): string
    {
        return Category::RESOURCE_NAME;
    }

    /**
     * Transform this model into a representation that can be consumed by a client.
     */
    public function transform(Category $model): array
    {
        return [
            'id' => $model->id,
            'name' => $model->name,
            'icon' => $model->icon,
            'description' => $model->description,
            'allowedEggs' => $model->allowed_eggs ?? [$model->egg_id],
            'allowEggChanges' => $model->allow_egg_changes ?? true,
        ];
    }
}
