<?php

namespace Everest\Services\Billing;

use Everest\Models\Billing\Product;
use Everest\Models\Billing\BillingCycle;
use Everest\Exceptions\DisplayException;

class BillingCycleService
{
    /**
     * Calculate price for a product with a specific billing cycle.
     * 
     * @param Product $product
     * @param int $billingDays
     * @return array
     */
    public function calculatePrice(Product $product, int $billingDays): array
    {
        // Use the product's calculatePrice method
        return $product->calculatePrice($billingDays);
    }

    /**
     * Get all enabled billing cycles for a product with calculated prices.
     * 
     * @param Product $product
     * @param int|null $couponId Optional coupon ID to apply
     * @return array
     */
    public function getAvailableCycles(Product $product, ?int $couponId = null): array
    {
        $cycles = $product->enabledBillingCycles()->orderBy('days')->get();
        
        // If no billing cycles defined, return default 30-day cycle
        if ($cycles->isEmpty()) {
            $priceInfo = $this->calculatePrice($product, 30);
            return [
                [
                    'days' => 30,
                    'price' => $priceInfo['price'],
                    'multiplier' => $priceInfo['multiplier'],
                    'discount_percent' => $priceInfo['discount_percent'],
                    'is_default' => true,
                ]
            ];
        }

        $result = [];
        foreach ($cycles as $cycle) {
            $priceInfo = $this->calculatePrice($product, $cycle->days);
            $result[] = [
                'id' => $cycle->id,
                'days' => $cycle->days,
                'price' => $priceInfo['price'],
                'multiplier' => $priceInfo['multiplier'],
                'discount_percent' => $priceInfo['discount_percent'],
                'is_default' => $cycle->days === 30,
            ];
        }

        return $result;
    }

    /**
     * Validate that a billing cycle is enabled for a product.
     * 
     * @param Product $product
     * @param int $billingDays
     * @throws DisplayException
     */
    public function validateBillingCycle(Product $product, int $billingDays): void
    {
        $cycle = $product->billingCycles()
            ->where('days', $billingDays)
            ->where('is_enabled', true)
            ->first();

        if (!$cycle) {
            // Check if product has any billing cycles defined
            $hasCycles = $product->billingCycles()->exists();
            
            if ($hasCycles) {
                throw new DisplayException(
                    "The selected billing cycle ({$billingDays} days) is not available for this product."
                );
            }
            
            // If no billing cycles defined, allow any reasonable value as fallback
            if ($billingDays < 1 || $billingDays > 365) {
                throw new DisplayException(
                    "Billing cycle must be between 1 and 365 days."
                );
            }
        }
    }

    /**
     * Create or update billing cycles for a product.
     * 
     * @param Product $product
     * @param array $cycles Array of ['days' => int, 'is_enabled' => bool]
     * @return void
     */
    public function syncBillingCycles(Product $product, array $cycles): void
    {
        foreach ($cycles as $cycleData) {
            BillingCycle::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'days' => $cycleData['days'],
                ],
                [
                    'is_enabled' => $cycleData['is_enabled'] ?? true,
                ]
            );
        }
    }
}
