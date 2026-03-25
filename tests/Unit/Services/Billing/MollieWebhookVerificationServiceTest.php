<?php

namespace Everest\Tests\Unit\Services\Billing;

use Mockery;
use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Everest\Models\Billing\Order;
use Everest\Services\Billing\MollieWebhookVerificationService;

class MollieWebhookVerificationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testRejectsWebhookWhenTokenIsMissing(): void
    {
        $service = new MollieWebhookVerificationService();

        $request = Request::create('/api/webhooks/mollie', 'POST', ['id' => 'tr_123456789']);

        $result = $service->validate($request);

        $this->assertFalse($result['valid']);
        $this->assertSame(401, $result['status']);
        $this->assertSame('missing_webhook_token', $result['reason']);
    }

    public function testRejectsWebhookWhenTokenDoesNotMatchOrder(): void
    {
        $service = new MollieWebhookVerificationService();

        $query = Mockery::mock();
        Mockery::mock('alias:' . Order::class)
            ->shouldReceive('where')
            ->once()
            ->with('payment_token', 'cf7a4d44-83cb-4ebc-b151-859d6b26dff5')
            ->andReturn($query);

        $query->shouldReceive('where')->once()->with('mollie_payment_id', 'tr_123456789')->andReturnSelf();
        $query->shouldReceive('latest')->once()->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturnNull();

        $request = Request::create('/api/webhooks/mollie?token=cf7a4d44-83cb-4ebc-b151-859d6b26dff5', 'POST', [
            'id' => 'tr_123456789',
        ]);

        $result = $service->validate($request);

        $this->assertFalse($result['valid']);
        $this->assertSame(401, $result['status']);
        $this->assertSame('invalid_webhook_token', $result['reason']);
    }

    public function testAcceptsWebhookWhenTokenMatchesStoredOrder(): void
    {
        $service = new MollieWebhookVerificationService();

        $order = new Order();
        $query = Mockery::mock();
        Mockery::mock('alias:' . Order::class)
            ->shouldReceive('where')
            ->once()
            ->with('payment_token', 'cf7a4d44-83cb-4ebc-b151-859d6b26dff5')
            ->andReturn($query);

        $query->shouldReceive('where')->once()->with('mollie_payment_id', 'tr_123456789')->andReturnSelf();
        $query->shouldReceive('latest')->once()->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn($order);

        $request = Request::create('/api/webhooks/mollie?token=cf7a4d44-83cb-4ebc-b151-859d6b26dff5', 'POST', [
            'id' => 'tr_123456789',
        ]);

        $result = $service->validate($request);

        $this->assertTrue($result['valid']);
        $this->assertSame('tr_123456789', $result['payment_id']);
        $this->assertSame($order, $result['order']);
    }
}
