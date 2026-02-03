<?php

namespace Everest\Tests\Unit\Services\Billing;

use Mockery;
use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\Category;
use Everest\Models\Billing\BillingException as BillingExceptionModel;
use Everest\Exceptions\Billing\BillingException;
use Everest\Services\Billing\PayPalPaymentService;

class PayPalPaymentServiceTest extends TestCase
{
    private PayPalPaymentService $service;

    /**
     * Setup test instance.
     */
    public function setUp(): void
    {
        parent::setUp();
        
        // Mock PayPal configuration
        config()->set('modules.billing.paypal_standalone', [
            'client_id' => 'test_client_id',
            'client_secret' => 'test_client_secret',
            'mode' => 'sandbox',
        ]);

        $this->service = new PayPalPaymentService();
    }

    /**
     * Test that PayPal service throws exception when credentials are missing.
     */
    public function testThrowsExceptionWhenCredentialsAreMissing()
    {
        config()->set('modules.billing.paypal_standalone', [
            'client_id' => null,
            'client_secret' => null,
            'mode' => 'sandbox',
        ]);

        $service = new PayPalPaymentService();
        $product = $this->createMockProduct();

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('PayPal is not configured');

        $service->createOrder($product, 10.00, null, 'http://return', 'http://cancel');
    }

    /**
     * Test that authentication failure throws proper BillingException.
     */
    public function testAuthenticationFailureThrowsBillingException()
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $product = $this->createMockProduct();

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('Failed to authenticate with PayPal');

        $service = new PayPalPaymentService();
        $service->createOrder($product, 10.00, null, 'http://return', 'http://cancel');
    }

    /**
     * Test that order creation failure throws BillingException with context.
     */
    public function testOrderCreationFailureThrowsBillingExceptionWithContext()
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'test_token',
                'expires_in' => 3600,
            ], 200),
            '*/v2/checkout/orders' => Http::response(['error' => 'invalid_request'], 400),
        ]);

        $product = $this->createMockProduct();

        try {
            $service = new PayPalPaymentService();
            $service->createOrder($product, 19.99, null, 'http://return', 'http://cancel');
            $this->fail('Expected BillingException was not thrown');
        } catch (BillingException $e) {
            $this->assertStringContainsString('Failed to create PayPal order', $e->getMessage());
            $this->assertEquals(BillingExceptionModel::TYPE_PAYMENT, $e->getExceptionType());
            $this->assertEquals('paypal', $e->getPaymentProcessor());
            $this->assertArrayHasKey('product_id', $e->getContext());
            $this->assertArrayHasKey('amount', $e->getContext());
        }
    }

    /**
     * Test that successful order creation returns order data.
     */
    public function testSuccessfulOrderCreationReturnsOrderData()
    {
        $orderId = 'ORDER123';
        
        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'test_token',
                'expires_in' => 3600,
            ], 200),
            '*/v2/checkout/orders' => Http::response([
                'id' => $orderId,
                'status' => 'CREATED',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://paypal.com/approve'],
                ],
            ], 201),
        ]);

        $product = $this->createMockProduct();
        $service = new PayPalPaymentService();
        $result = $service->createOrder($product, 19.99, null, 'http://return', 'http://cancel');

        $this->assertEquals($orderId, $result['id']);
        $this->assertEquals('CREATED', $result['status']);
    }

    /**
     * Test that invalid order ID format throws validation exception.
     */
    public function testInvalidOrderIdFormatThrowsValidationException()
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'test_token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $service = new PayPalPaymentService();

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('Invalid PayPal order ID format');

        $service->getOrder('invalid order id with spaces');
    }

    /**
     * Test that order retrieval failure throws BillingException.
     */
    public function testOrderRetrievalFailureThrowsBillingException()
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'test_token',
                'expires_in' => 3600,
            ], 200),
            '*/v2/checkout/orders/*' => Http::response(['error' => 'not_found'], 404),
        ]);

        $service = new PayPalPaymentService();

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('Failed to fetch PayPal order');

        $service->getOrder('ORDER123');
    }

    /**
     * Test that capture failure throws BillingException.
     */
    public function testCaptureFailureThrowsBillingException()
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'test_token',
                'expires_in' => 3600,
            ], 200),
            '*/v2/checkout/orders/*/capture' => Http::response(['error' => 'capture_failed'], 422),
        ]);

        $service = new PayPalPaymentService();

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('Failed to capture PayPal order');

        $service->captureOrder('ORDER123');
    }

    /**
     * Test that successful capture returns capture data.
     */
    public function testSuccessfulCaptureReturnsCaptureData()
    {
        Http::fake([
            '*/v1/oauth2/token' => Http::response([
                'access_token' => 'test_token',
                'expires_in' => 3600,
            ], 200),
            '*/v2/checkout/orders/*/capture' => Http::response([
                'id' => 'ORDER123',
                'status' => 'COMPLETED',
            ], 200),
        ]);

        $service = new PayPalPaymentService();
        $result = $service->captureOrder('ORDER123');

        $this->assertEquals('ORDER123', $result['id']);
        $this->assertEquals('COMPLETED', $result['status']);
    }

    /**
     * Create a mock product for testing.
     */
    private function createMockProduct(): Product
    {
        $category = Mockery::mock(Category::class);
        $category->shouldReceive('getAttribute')->with('nest_id')->andReturn(1);
        
        $product = Mockery::mock(Product::class);
        $product->shouldReceive('getAttribute')->with('id')->andReturn(123);
        $product->shouldReceive('getAttribute')->with('name')->andReturn('Test Product');
        $product->shouldReceive('getAttribute')->with('category')->andReturn($category);
        
        return $product;
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
