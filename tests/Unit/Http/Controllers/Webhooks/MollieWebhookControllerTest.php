<?php

namespace Everest\Tests\Unit\Http\Controllers\Webhooks;

use Mockery;
use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Everest\Http\Controllers\Webhooks\MollieWebhookController;
use Everest\Services\Billing\MolliePaymentService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Services\Billing\ServerFulfillmentService;
use Everest\Services\Billing\MollieWebhookVerificationService;

class MollieWebhookControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testRejectsInvalidWebhookBeforeProviderLookup(): void
    {
        $mollieService = Mockery::mock(MolliePaymentService::class);
        $mollieService->shouldNotReceive('getPayment');

        $verificationService = Mockery::mock(MollieWebhookVerificationService::class);
        $verificationService->shouldReceive('validate')->once()->andReturn([
            'valid' => false,
            'status' => 401,
            'reason' => 'missing_webhook_token',
            'context' => [],
        ]);

        $validationService = Mockery::mock(BillingValidationService::class);
        $validationService->shouldNotReceive('validateBillingEnabled');

        $fulfillmentService = Mockery::mock(ServerFulfillmentService::class);
        $fulfillmentService->shouldNotReceive('fulfillOrder');

        $controller = new MollieWebhookController(
            $mollieService,
            $verificationService,
            $validationService,
            $fulfillmentService
        );

        $response = $controller->handle(Request::create('/api/webhooks/mollie', 'POST', [
            'id' => 'tr_123456789',
        ]));

        $this->assertSame(401, $response->getStatusCode());
    }

}
