<?php

namespace Everest\Tests\Unit\Services\Billing;

use Mockery;
use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Everest\Models\Egg;
use Everest\Models\Server;
use Everest\Models\Allocation;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\Category;
use Everest\Models\Billing\BillingException as BillingExceptionModel;
use Everest\Exceptions\Billing\BillingException;
use Everest\Exceptions\DisplayException;
use Everest\Services\Billing\CreateServerService;
use Everest\Services\Servers\ServerCreationService;
use Everest\Services\Servers\VariableValidatorService;

class CreateServerServiceTest extends TestCase
{
    private CreateServerService $service;
    private $serverCreation;
    private $variableValidator;

    /**
     * Setup test instance.
     */
    public function setUp(): void
    {
        parent::setUp();
        
        $this->serverCreation = Mockery::mock(ServerCreationService::class);
        $this->variableValidator = Mockery::mock(VariableValidatorService::class);
        
        $this->service = new CreateServerService(
            $this->serverCreation,
            $this->variableValidator
        );
    }

    /**
     * Test that no allocation available throws BillingException.
     */
    public function testNoAllocationAvailableThrowsBillingException()
    {
        $request = $this->createMockRequest();
        $product = $this->createMockProduct();
        $order = $this->createMockOrder();
        
        $metadata = new \stdClass();
        $metadata->node_id = 1;
        $metadata->variables = null;

        // Mock Allocation query to return null
        Allocation::shouldReceive('where')->andReturnSelf();
        Allocation::shouldReceive('first')->andReturn(null);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('No allocations are available for deployment');

        try {
            $this->service->processFree($request, $product, 1, $order, [], 'Test Server');
        } catch (BillingException $e) {
            $this->assertEquals(BillingExceptionModel::TYPE_DEPLOYMENT, $e->getExceptionType());
            $this->assertEquals($order->id, $e->getOrderId());
            throw $e;
        }
    }

    /**
     * Test that server creation failure throws BillingException with context.
     */
    public function testServerCreationFailureThrowsBillingExceptionWithContext()
    {
        $request = $this->createMockRequest();
        $product = $this->createMockProduct();
        $order = $this->createMockOrder();
        
        $metadata = new \stdClass();
        $metadata->node_id = 1;
        $metadata->variables = null;

        // Mock allocation exists
        $allocation = Mockery::mock(Allocation::class);
        $allocation->shouldReceive('getAttribute')->with('id')->andReturn(100);
        
        Allocation::shouldReceive('where')->andReturnSelf();
        Allocation::shouldReceive('first')->andReturn($allocation);

        // Mock egg
        $egg = Mockery::mock(Egg::class);
        $egg->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $egg->shouldReceive('getAttribute')->with('startup')->andReturn('startup command');
        $egg->shouldReceive('getAttribute')->with('docker_images')->andReturn(['image1']);
        
        Egg::shouldReceive('findOrFail')->andReturn($egg);

        // Mock server creation to fail
        $this->serverCreation->shouldReceive('handle')
            ->once()
            ->andThrow(new DisplayException('Server creation failed due to insufficient resources'));

        try {
            $this->service->processFree($request, $product, 1, $order, [], 'Test Server');
            $this->fail('Expected BillingException was not thrown');
        } catch (BillingException $e) {
            $this->assertStringContainsString('Unable to create server', $e->getMessage());
            $this->assertEquals(BillingExceptionModel::TYPE_DEPLOYMENT, $e->getExceptionType());
            $this->assertEquals($order->id, $e->getOrderId());
            $this->assertArrayHasKey('product_id', $e->getContext());
            $this->assertArrayHasKey('node_id', $e->getContext());
        }
    }

    /**
     * Test that unexpected exception is wrapped in BillingException.
     */
    public function testUnexpectedExceptionIsWrappedInBillingException()
    {
        $request = $this->createMockRequest();
        $product = $this->createMockProduct();
        $order = $this->createMockOrder();
        
        $metadata = new \stdClass();
        $metadata->node_id = 1;
        $metadata->variables = null;

        // Mock allocation exists
        $allocation = Mockery::mock(Allocation::class);
        $allocation->shouldReceive('getAttribute')->with('id')->andReturn(100);
        
        Allocation::shouldReceive('where')->andReturnSelf();
        Allocation::shouldReceive('first')->andReturn($allocation);

        // Mock egg
        $egg = Mockery::mock(Egg::class);
        $egg->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $egg->shouldReceive('getAttribute')->with('startup')->andReturn('startup command');
        $egg->shouldReceive('getAttribute')->with('docker_images')->andReturn(['image1']);
        
        Egg::shouldReceive('findOrFail')->andReturn($egg);

        // Mock server creation to throw unexpected exception
        $this->serverCreation->shouldReceive('handle')
            ->once()
            ->andThrow(new \RuntimeException('Unexpected database error'));

        try {
            $this->service->processFree($request, $product, 1, $order, [], 'Test Server');
            $this->fail('Expected BillingException was not thrown');
        } catch (BillingException $e) {
            $this->assertStringContainsString('An unexpected error occurred', $e->getMessage());
            $this->assertEquals(BillingExceptionModel::TYPE_DEPLOYMENT, $e->getExceptionType());
        }
    }

    /**
     * Create a mock request.
     */
    private function createMockRequest(): Request
    {
        $user = Mockery::mock('Everest\Models\User');
        $user->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $user->shouldReceive('getAttribute')->with('username')->andReturn('testuser');
        
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('user')->andReturn($user);
        
        return $request;
    }

    /**
     * Create a mock product.
     */
    private function createMockProduct(): Product
    {
        $category = Mockery::mock(Category::class);
        $category->shouldReceive('getAttribute')->with('nest_id')->andReturn(1);
        $category->shouldReceive('getDefaultEggId')->andReturn(1);
        
        $product = Mockery::mock(Product::class);
        $product->shouldReceive('getAttribute')->with('id')->andReturn(123);
        $product->shouldReceive('getAttribute')->with('name')->andReturn('Test Product');
        $product->shouldReceive('getAttribute')->with('category')->andReturn($category);
        $product->shouldReceive('getAttribute')->with('memory_limit')->andReturn(1024);
        $product->shouldReceive('getAttribute')->with('disk_limit')->andReturn(5000);
        $product->shouldReceive('getAttribute')->with('cpu_limit')->andReturn(100);
        $product->shouldReceive('getAttribute')->with('database_limit')->andReturn(1);
        $product->shouldReceive('getAttribute')->with('backup_limit')->andReturn(1);
        $product->shouldReceive('getAttribute')->with('allocation_limit')->andReturn(1);
        $product->shouldReceive('getRenewalDays')->andReturn(30);
        
        return $product;
    }

    /**
     * Create a mock order.
     */
    private function createMockOrder(): Order
    {
        $order = Mockery::mock(Order::class);
        $order->shouldReceive('getAttribute')->with('id')->andReturn(456);
        $order->shouldReceive('getAttribute')->with('egg_id')->andReturn(1);
        
        return $order;
    }

    /**
     * Clean up after tests.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
