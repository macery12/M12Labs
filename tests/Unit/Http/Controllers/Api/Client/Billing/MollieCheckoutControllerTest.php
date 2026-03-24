<?php

namespace Everest\Tests\Unit\Http\Controllers\Api\Client\Billing;

use Mockery;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Mollie\Api\Resources\Payment;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Models\User;
use Everest\Services\Billing\CreateOrderService;
use Everest\Services\Billing\CreateServerService;
use Everest\Services\Billing\MolliePaymentService;
use Everest\Services\Billing\OrderProcessorService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Services\Billing\ServerFulfillmentService;
use Everest\Http\Controllers\Api\Client\Billing\MollieCheckoutController;

class MollieCheckoutControllerTest extends TestCase
{
    private string $dbPath;

    public function setUp(): void
    {
        parent::setUp();

        $dbPath = tempnam(sys_get_temp_dir(), 'mollie_checkout_controller_test_');
        if ($dbPath === false) {
            throw new \RuntimeException('Failed to create temporary sqlite database for Mollie checkout controller test.');
        }
        $this->dbPath = $dbPath;

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', $dbPath);

        Schema::dropIfExists('products');
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        DB::table('products')->insert([
            'id' => 123,
            'name' => 'Test Product',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        @unlink($this->dbPath);

        parent::tearDown();
    }

    public function testCreatePaymentUsesRequestedBillingDaysForPricingAndOrderCreation(): void
    {
        $product = Product::findOrFail(123);

        $validation = Mockery::mock(BillingValidationService::class);
        $validation->shouldReceive('calculatePriceWithCoupon')
            ->once()
            ->with(
                Mockery::on(fn (Product $candidate) => $candidate->id === 123),
                77,
                'new',
                10,
                null,
                42
            )
            ->andReturn(['finalPrice' => 19.99]);
        $validation->shouldReceive('validatePriceType')
            ->once()
            ->with(19.99, false);

        $mollieService = Mockery::mock(MolliePaymentService::class);
        $molliePayment = Mockery::mock(Payment::class);
        $molliePayment->id = 'tr_test123';
        $molliePayment->shouldReceive('getCheckoutUrl')
            ->once()
            ->andReturn('https://www.mollie.com/payscreen/test');
        $mollieService->shouldReceive('createPayment')
            ->once()
            ->with(
                Mockery::on(fn (Product $candidate) => $candidate->id === 123),
                19.99,
                77,
                Mockery::type('string'),
                Mockery::type('string')
            )
            ->andReturn($molliePayment);

        $orderService = Mockery::mock(CreateOrderService::class);
        $orderService->shouldReceive('create')
            ->once()
            ->with(
                null,
                Mockery::on(fn ($user) => $user->id === 42),
                Mockery::on(fn (Product $candidate) => $candidate->id === 123),
                Order::STATUS_PENDING,
                Order::TYPE_NEW,
                77,
                null,
                Mockery::on(fn (array $data) => ($data['billing_days'] ?? null) === 10)
            );

        $controller = new MollieCheckoutController(
            $mollieService,
            $validation,
            Mockery::mock(OrderProcessorService::class),
            $orderService,
            Mockery::mock(CreateServerService::class),
            Mockery::mock(ServerFulfillmentService::class)
        );

        $user = new User();
        $user->id = 42;

        $request = Request::create('/api/client/billing/products/123/mollie/payment', 'POST', [
            'coupon_id' => 77,
            'billing_days' => 10,
            'return_url' => 'https://panel.example/account/billing/processing',
        ]);
        $request->setUserResolver(fn () => $user);

        $response = $controller->createPayment($request, 123);

        $this->assertSame(200, $response->getStatusCode());
    }
}
