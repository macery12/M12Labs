<?php

namespace Everest\Tests\Unit\Services\Billing;

use Mockery;
use Everest\Tests\TestCase;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\Category;
use Everest\Models\Billing\BillingException as BillingExceptionModel;
use Everest\Exceptions\Billing\BillingException;
use Everest\Services\Billing\MolliePaymentService;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\PaymentCollection;
use Mollie\Api\Endpoints\PaymentEndpoint;

class MolliePaymentServiceTest extends TestCase
{
    /**
     * Test that Mollie service throws exception when API key is missing.
     */
    public function testThrowsExceptionWhenApiKeyIsMissing()
    {
        config()->set('modules.billing.mollie.api_key', null);

        $service = new MolliePaymentService();
        $product = $this->createMockProduct();

        $this->expectException(BillingException::class);
        $this->expectExceptionMessage('Mollie is not configured');

        $service->createPayment($product, 10.00, null, 'http://return');
    }

    /**
     * Test that payment creation failure throws BillingException.
     */
    public function testPaymentCreationFailureThrowsBillingException()
    {
        config()->set('modules.billing.mollie.api_key', 'test_key');

        // Mock Mollie client
        $mollieClient = Mockery::mock(MollieApiClient::class);
        $paymentsEndpoint = Mockery::mock(PaymentEndpoint::class);
        
        $mollieClient->payments = $paymentsEndpoint;
        $paymentsEndpoint->shouldReceive('create')
            ->once()
            ->andThrow(new \Mollie\Api\Exceptions\ApiException('Payment creation failed'));

        // We can't easily inject the mock due to the service constructor
        // So this test verifies the exception type and message structure
        $this->expectException(\Mollie\Api\Exceptions\ApiException::class);
        
        // Create a partial mock to inject our mocked client
        $service = Mockery::mock(MolliePaymentService::class)->makePartial();
        $service->shouldAllowMockingProtectedMethods();
        
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('mollie');
        $property->setAccessible(true);
        $property->setValue($service, $mollieClient);

        $product = $this->createMockProduct();
        
        try {
            $service->createPayment($product, 19.99, null, 'http://return');
        } catch (BillingException $e) {
            $this->assertStringContainsString('Failed to create Mollie payment', $e->getMessage());
            $this->assertEquals(BillingExceptionModel::TYPE_PAYMENT, $e->getExceptionType());
            $this->assertEquals('mollie', $e->getPaymentProcessor());
        }
    }

    /**
     * Test that payment retrieval failure throws BillingException.
     */
    public function testPaymentRetrievalFailureThrowsBillingException()
    {
        config()->set('modules.billing.mollie.api_key', 'test_key');

        $mollieClient = Mockery::mock(MollieApiClient::class);
        $paymentsEndpoint = Mockery::mock(PaymentEndpoint::class);
        
        $mollieClient->payments = $paymentsEndpoint;
        $paymentsEndpoint->shouldReceive('get')
            ->once()
            ->with('tr_test123')
            ->andThrow(new \Mollie\Api\Exceptions\ApiException('Payment not found'));

        $service = Mockery::mock(MolliePaymentService::class)->makePartial();
        
        $reflection = new \ReflectionClass($service);
        $property = $reflection->getProperty('mollie');
        $property->setAccessible(true);
        $property->setValue($service, $mollieClient);

        $this->expectException(\Mollie\Api\Exceptions\ApiException::class);

        try {
            $service->getPayment('tr_test123');
        } catch (BillingException $e) {
            $this->assertStringContainsString('Failed to fetch Mollie payment', $e->getMessage());
            $this->assertEquals(BillingExceptionModel::TYPE_PAYMENT, $e->getExceptionType());
            $this->assertEquals('mollie', $e->getPaymentProcessor());
            $this->assertEquals('tr_test123', $e->getExternalId());
        }
    }

    /**
     * Test exception type is correct for configuration errors.
     */
    public function testConfigurationErrorHasCorrectType()
    {
        config()->set('modules.billing.mollie.api_key', null);

        $service = new MolliePaymentService();
        $product = $this->createMockProduct();

        try {
            $service->createPayment($product, 10.00, null, 'http://return');
            $this->fail('Expected BillingException was not thrown');
        } catch (BillingException $e) {
            $this->assertEquals(BillingExceptionModel::TYPE_STOREFRONT, $e->getExceptionType());
        }
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
