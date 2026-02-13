<?php

namespace Everest\Tests\Unit\Models\Billing;

use Everest\Models\Node;
use Everest\Models\Setting;
use Everest\Tests\TestCase;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NodePriceMultiplierTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that default node price multiplier is 1.00.
     */
    public function testDefaultNodePriceMultiplierIsOne()
    {
        $node = Node::factory()->create();

        $this->assertEquals(1.0, $node->price_multiplier);
    }

    /**
     * Test that node price multiplier is fillable.
     */
    public function testNodePriceMultiplierIsFillable()
    {
        $node = new Node();
        $fillable = $node->getFillable();

        $this->assertContains('price_multiplier', $fillable);
    }

    /**
     * Test that node price multiplier is cast to float.
     */
    public function testNodePriceMultiplierIsCastToFloat()
    {
        $node = new Node();
        $casts = $node->getCasts();

        $this->assertArrayHasKey('price_multiplier', $casts);
        $this->assertEquals('float', $casts['price_multiplier']);
    }

    /**
     * Test that product calculatePrice applies node multiplier correctly.
     */
    public function testProductCalculatePriceAppliesNodeMultiplier()
    {
        // Create a node with 1.25x multiplier
        $node = Node::factory()->create([
            'price_multiplier' => 1.25,
        ]);

        // Create a product with a base price
        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test',
            'nest_id' => 1,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 10.0,
            'category_id' => $category->id,
            'cpu_limit' => 100,
            'memory_limit' => 1024,
            'disk_limit' => 5000,
            'database_limit' => 1,
            'backup_limit' => 1,
            'allocation_limit' => 1,
        ]);

        // Set default billing days
        Setting::set('settings::modules:billing:renewal:default_billing_days', '30');

        // Calculate price for 30 days with node multiplier
        $result = $product->calculatePrice(30, $node->id);

        // Expected: $10 * 1.0 (30 days multiplier) * 1.25 (node multiplier) = $12.50
        $this->assertEquals(12.50, $result['price']);
        $this->assertEquals(1.0, $result['multiplier']);
        $this->assertEquals(1.25, $result['node_multiplier']);
    }

    /**
     * Test that product calculatePrice uses 1.0x when no node specified.
     */
    public function testProductCalculatePriceDefaultsToOneWithoutNode()
    {
        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test',
            'nest_id' => 1,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 10.0,
            'category_id' => $category->id,
            'cpu_limit' => 100,
            'memory_limit' => 1024,
            'disk_limit' => 5000,
            'database_limit' => 1,
            'backup_limit' => 1,
            'allocation_limit' => 1,
        ]);

        Setting::set('settings::modules:billing:renewal:default_billing_days', '30');

        // Calculate price without node
        $result = $product->calculatePrice(30);

        // Expected: $10 * 1.0 (30 days multiplier) * 1.0 (default node multiplier) = $10.00
        $this->assertEquals(10.0, $result['price']);
        $this->assertEquals(1.0, $result['node_multiplier']);
    }

    /**
     * Test that product calculatePrice handles removed node gracefully.
     */
    public function testProductCalculatePriceHandlesRemovedNodeGracefully()
    {
        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test',
            'nest_id' => 1,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 10.0,
            'category_id' => $category->id,
            'cpu_limit' => 100,
            'memory_limit' => 1024,
            'disk_limit' => 5000,
            'database_limit' => 1,
            'backup_limit' => 1,
            'allocation_limit' => 1,
        ]);

        Setting::set('settings::modules:billing:renewal:default_billing_days', '30');

        // Calculate price with non-existent node ID
        $result = $product->calculatePrice(30, 99999);

        // Should fallback to 1.0x
        $this->assertEquals(10.0, $result['price']);
        $this->assertEquals(1.0, $result['node_multiplier']);
    }

    /**
     * Test price rounding is consistent (2 decimal places).
     */
    public function testPriceRoundingIsConsistent()
    {
        $node = Node::factory()->create([
            'price_multiplier' => 1.234567,
        ]);

        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test',
            'nest_id' => 1,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 10.0,
            'category_id' => $category->id,
            'cpu_limit' => 100,
            'memory_limit' => 1024,
            'disk_limit' => 5000,
            'database_limit' => 1,
            'backup_limit' => 1,
            'allocation_limit' => 1,
        ]);

        Setting::set('settings::modules:billing:renewal:default_billing_days', '30');

        $result = $product->calculatePrice(30, $node->id);

        // Price should be rounded to 2 decimal places
        $this->assertEquals(12.35, $result['price']); // 10 * 1.234567 = 12.34567, rounded to 12.35
        $this->assertIsFloat($result['price']);
    }

    /**
     * Test zero node multiplier results in zero price.
     */
    public function testZeroNodeMultiplierResultsInZeroPrice()
    {
        $node = Node::factory()->create([
            'price_multiplier' => 0.0,
        ]);

        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test',
            'nest_id' => 1,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 10.0,
            'category_id' => $category->id,
            'cpu_limit' => 100,
            'memory_limit' => 1024,
            'disk_limit' => 5000,
            'database_limit' => 1,
            'backup_limit' => 1,
            'allocation_limit' => 1,
        ]);

        Setting::set('settings::modules:billing:renewal:default_billing_days', '30');

        $result = $product->calculatePrice(30, $node->id);

        $this->assertEquals(0.0, $result['price']);
        $this->assertEquals(0.0, $result['node_multiplier']);
    }

    /**
     * Test discount node multiplier (< 1.0).
     */
    public function testDiscountNodeMultiplier()
    {
        $node = Node::factory()->create([
            'price_multiplier' => 0.85, // 15% discount
        ]);

        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test',
            'nest_id' => 1,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 10.0,
            'category_id' => $category->id,
            'cpu_limit' => 100,
            'memory_limit' => 1024,
            'disk_limit' => 5000,
            'database_limit' => 1,
            'backup_limit' => 1,
            'allocation_limit' => 1,
        ]);

        Setting::set('settings::modules:billing:renewal:default_billing_days', '30');

        $result = $product->calculatePrice(30, $node->id);

        // Expected: $10 * 1.0 * 0.85 = $8.50
        $this->assertEquals(8.50, $result['price']);
        $this->assertEquals(0.85, $result['node_multiplier']);
    }

    /**
     * Test premium node multiplier (> 1.0).
     */
    public function testPremiumNodeMultiplier()
    {
        $node = Node::factory()->create([
            'price_multiplier' => 1.50, // 50% premium
        ]);

        $category = Category::create([
            'name' => 'Test Category',
            'description' => 'Test',
            'nest_id' => 1,
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'description' => 'Test',
            'price' => 10.0,
            'category_id' => $category->id,
            'cpu_limit' => 100,
            'memory_limit' => 1024,
            'disk_limit' => 5000,
            'database_limit' => 1,
            'backup_limit' => 1,
            'allocation_limit' => 1,
        ]);

        Setting::set('settings::modules:billing:renewal:default_billing_days', '30');

        $result = $product->calculatePrice(30, $node->id);

        // Expected: $10 * 1.0 * 1.50 = $15.00
        $this->assertEquals(15.0, $result['price']);
        $this->assertEquals(1.50, $result['node_multiplier']);
    }
}
