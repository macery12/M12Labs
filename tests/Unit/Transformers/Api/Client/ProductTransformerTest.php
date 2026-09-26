<?php

namespace Everest\Tests\Unit\Transformers\Api\Client;

use Everest\Tests\TestCase;
use Everest\Models\Billing\Product;
use Everest\Transformers\Api\Client\ProductTransformer;

class ProductTransformerTest extends TestCase
{
    /**
     * Deleting a category leaves its products behind. One of them was pinned
     * as the storefront's featured plan, and transforming it 500'd the Store
     * for every player.
     */
    public function testAProductWhoseCategoryIsGoneStillTransformsAsUnavailable(): void
    {
        $product = new Product();
        $product->forceFill([
            'id' => 11,
            'name' => 'Orphaned plan',
            'price' => 5.0,
            'cpu_limit' => 100,
            'memory_limit' => 1024,
            'disk_limit' => 2048,
            'backup_limit' => 1,
            'database_limit' => 1,
            'allocation_limit' => 1,
        ]);
        $product->setRelation('category', null);

        $attributes = (new ProductTransformer())->transform($product);

        $this->assertFalse($attributes['available']);
        $this->assertNull($attributes['egg_id']);
        $this->assertSame([], $attributes['allowed_eggs']);
        $this->assertFalse($attributes['allow_egg_changes']);
        $this->assertSame('Orphaned plan', $attributes['name']);
        $this->assertSame(1024, $attributes['limits']['memory']);
    }
}
