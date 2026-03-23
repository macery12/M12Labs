<?php

namespace Everest\Tests\Unit\Services\Billing;

use Everest\Exceptions\DisplayException;
use Everest\Repositories\Wings\DaemonServerRepository;
use Everest\Services\Billing\BillingValidationService;
use Everest\Services\Billing\NodeAvailabilityService;
use Everest\Tests\TestCase;
use Mockery;

class BillingValidationServiceTest extends TestCase
{
    private BillingValidationService $service;

    public function setUp(): void
    {
        parent::setUp();

        $this->service = new BillingValidationService(
            Mockery::mock(DaemonServerRepository::class),
            Mockery::mock(NodeAvailabilityService::class),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testValidatePriceTypeTreatsTinyOrNegativeTotalsAsFree(): void
    {
        $this->service->validatePriceType(0.0001, true);
        $this->service->validatePriceType(-0.25, true);

        $this->addToAssertionCount(1);
    }

    public function testValidatePriceTypeRejectsPaidFlowForFreeTotals(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('This order total is $0. Please use the free order process instead of payment.');

        $this->service->validatePriceType(0.0, false);
    }

    public function testValidatePriceTypeRequiresPaymentWhenAboveThreshold(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessage('This product is not free. Please use the payment process.');

        $this->service->validatePriceType(0.01, true);
    }
}
