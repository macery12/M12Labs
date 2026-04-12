<?php

namespace Everest\Tests\Unit\Services\Billing;

use Everest\Tests\TestCase;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Models\Billing\BillingCycle;
use Everest\Services\Billing\BillingCycleService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Everest\Contracts\Repository\SettingsRepositoryInterface;

class BillingCycleServiceTest extends TestCase
{
    private BillingCycleService $service;

    public function setUp(): void
    {
        parent::setUp();

        $this->service = new BillingCycleService();
    }

    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------
    // validateBillingCycle — product that HAS custom cycles
    // ---------------------------------------------------------------------------

    public function testValidateBillingCyclePassesWhenMatchingEnabledCycleExists(): void
    {
        $cycle = \Mockery::mock(BillingCycle::class);

        $relation = $this->makeRelationMock(hasCycles: true, foundCycle: $cycle);
        $product = $this->makeProductMock($relation);

        // No exception expected
        $this->service->validateBillingCycle($product, 30);
        $this->assertTrue(true);
    }

    public function testValidateBillingCycleThrowsWhenCycleNotFoundAndProductHasCycles(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches('/not available for this product/');

        $relation = $this->makeRelationMock(hasCycles: true, foundCycle: null);
        $product = $this->makeProductMock($relation);

        $this->service->validateBillingCycle($product, 14);
    }

    // ---------------------------------------------------------------------------
    // validateBillingCycle — product with NO custom cycles (falls back to global default)
    // ---------------------------------------------------------------------------

    public function testValidateBillingCycleAcceptsDefaultDaysWhenProductHasNoCycles(): void
    {
        $this->bindSettingsDefault(30);

        $relation = $this->makeRelationMock(hasCycles: false, foundCycle: null);
        $product = $this->makeProductMock($relation);

        // The submitted days match the current global default — must succeed
        $this->service->validateBillingCycle($product, 30);
        $this->assertTrue(true);
    }

    public function testValidateBillingCycleRejectsNonDefaultDaysWhenProductHasNoCycles(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches('/not available for this product/');

        $this->bindSettingsDefault(30);

        $relation = $this->makeRelationMock(hasCycles: false, foundCycle: null);
        $product = $this->makeProductMock($relation);

        // Submitting 1 day when the global default is 30 must fail
        $this->service->validateBillingCycle($product, 1);
    }

    public function testValidateBillingCycleRejectsMaxDaysWhenProductHasNoCyclesAndDefaultIs30(): void
    {
        $this->expectException(DisplayException::class);
        $this->expectExceptionMessageMatches('/not available for this product/');

        $this->bindSettingsDefault(30);

        $relation = $this->makeRelationMock(hasCycles: false, foundCycle: null);
        $product = $this->makeProductMock($relation);

        $this->service->validateBillingCycle($product, 365);
    }

    public function testValidateBillingCycleAcceptsNonDefaultWhenGlobalDefaultChangedTo60(): void
    {
        $this->bindSettingsDefault(60);

        $relation = $this->makeRelationMock(hasCycles: false, foundCycle: null);
        $product = $this->makeProductMock($relation);

        // After the default changes to 60, submitting 60 must succeed
        $this->service->validateBillingCycle($product, 60);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------------------
    // reseedDefaultBillingCycle — early-return when old equals new
    // ---------------------------------------------------------------------------

    public function testReseedDoesNothingWhenOldAndNewDefaultAreEqual(): void
    {
        // When old === new there should be no DB activity whatsoever; just verify
        // the method returns without throwing.
        $this->service->reseedDefaultBillingCycle(30, 30);
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Bind a mock SettingsRepository that returns the given default billing days.
     */
    private function bindSettingsDefault(int $defaultDays): void
    {
        $repo = \Mockery::mock(SettingsRepositoryInterface::class);
        $repo->shouldReceive('get')
            ->with('settings::modules:billing:renewal:default_billing_days', 30)
            ->andReturn($defaultDays);

        $this->app->instance(SettingsRepositoryInterface::class, $repo);
    }

    /**
     * Build a HasMany relation mock that reports the requested existence and
     * supports fluent where() + first() chaining.
     */
    private function makeRelationMock(bool $hasCycles, ?BillingCycle $foundCycle): HasMany
    {
        /** @var HasMany&\Mockery\MockInterface $relation */
        $relation = \Mockery::mock(HasMany::class);
        $relation->shouldReceive('exists')->andReturn($hasCycles);
        $relation->shouldReceive('where')->andReturnSelf();
        $relation->shouldReceive('first')->andReturn($foundCycle);

        return $relation;
    }

    /**
     * Build a Product mock whose billingCycles() always returns the given relation.
     */
    private function makeProductMock(HasMany $relation): Product
    {
        /** @var Product&\Mockery\MockInterface $product */
        $product = \Mockery::mock(Product::class);
        $product->shouldReceive('billingCycles')->andReturn($relation);

        return $product;
    }
}
