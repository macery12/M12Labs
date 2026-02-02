<?php

namespace Everest\Services\Billing;

use Everest\Models\Setting;
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
     * Get all billing cycles for a product (admin view).
     * Includes all cycles with their enabled status.
     * 
     * @param Product $product
     * @return array
     */
    public function getAllCycles(Product $product): array
    {
        // Get default billing days from settings
        $defaultBillingDays = (int) Setting::get('settings::modules:billing:renewal:default_billing_days', 30);
        
        // Query ALL billing cycles for the product
        $cycles = BillingCycle::where('product_id', $product->id)
            ->orderBy('days')
            ->get();
        
        $result = [];
        foreach ($cycles as $cycle) {
            $priceInfo = $this->calculatePrice($product, $cycle->days);
            $result[] = [
                'id' => $cycle->id,
                'days' => $cycle->days,
                'price' => $priceInfo['price'],
                'multiplier' => $priceInfo['multiplier'],
                'discount_percent' => $priceInfo['discount_percent'],
                'is_default' => $cycle->days === $defaultBillingDays,
                'is_enabled' => $cycle->is_enabled,
            ];
        }

        return $result;
    }

    /**
     * Get all enabled billing cycles for a product with calculated prices.
     * Used for client checkout.
     * 
     * @param Product $product
     * @param int|null $couponId Optional coupon ID to apply
     * @return array
     */
    public function getAvailableCycles(Product $product, ?int $couponId = null): array
    {
        // Get default billing days from settings
        $defaultBillingDays = (int) Setting::get('settings::modules:billing:renewal:default_billing_days', 30);
        
        // Query enabled billing cycles directly with proper where clause
        $cycles = BillingCycle::where('product_id', $product->id)
            ->where('is_enabled', true)
            ->orderBy('days')
            ->get();
        
        // If no billing cycles defined, return default cycle based on global setting
        if ($cycles->isEmpty()) {
            $priceInfo = $this->calculatePrice($product, $defaultBillingDays);
            return [
                [
                    'days' => $defaultBillingDays,
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
                'is_default' => $cycle->days === $defaultBillingDays,
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
        $daysToKeep = [];
        
        foreach ($cycles as $cycleData) {
            $daysToKeep[] = $cycleData['days'];
            
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
        
        // Delete cycles that are no longer in the list
        $product->billingCycles()->whereNotIn('days', $daysToKeep)->delete();
    }
}
