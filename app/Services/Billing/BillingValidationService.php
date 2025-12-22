<?php

namespace Everest\Services\Billing;

use Everest\Models\Node;
use Everest\Models\User;
use Everest\Models\Server;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Coupon;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;

/**
 * Centralized validation service for billing operations.
 * 
 * This service consolidates all billing-related validation logic that was previously
 * duplicated across the old FreeProductController and PaymentController (now replaced by CheckoutController).
 */
class BillingValidationService
{
    /**
     * Validate that the billing module is enabled.
     * 
     * @throws DisplayException if billing is disabled
     */
    public function validateBillingEnabled(): void
    {
        if (!config('modules.billing.enabled')) {
            throw new DisplayException('The billing module is not enabled.');
        }
    }

    /**
     * Check if coupons are allowed for a specific order type.
     * 
     * @param string $orderType The order type (e.g., Order::TYPE_NEW, Order::TYPE_REN)
     * @return bool True if coupons are allowed for this order type
     */
    public function areCouponsAllowedForOrderType(string $orderType): bool
    {
        $couponUsage = config('modules.billing.coupon_usage', 'both');
        
        if ($couponUsage === 'disabled') {
            return false;
        }
        
        if ($couponUsage === 'both') {
            return true;
        }
        
        if ($couponUsage === 'purchases' && $orderType === Order::TYPE_NEW) {
            return true;
        }
        
        if ($couponUsage === 'renewals' && $orderType === Order::TYPE_REN) {
            return true;
        }
        
        return false;
    }

    /**
     * Validate that coupons are allowed for a specific order type.
     * 
     * @param string $orderType The order type (e.g., Order::TYPE_NEW, Order::TYPE_REN)
     * @throws DisplayException if coupons are not allowed for this order type
     */
    public function validateCouponUsageForOrderType(string $orderType): void
    {
        $couponUsage = config('modules.billing.coupon_usage', 'both');
        
        if ($couponUsage === 'disabled') {
            throw new DisplayException('Coupons are currently disabled.');
        }
        
        if ($couponUsage === 'purchases' && $orderType !== Order::TYPE_NEW) {
            throw new DisplayException('Coupons can only be used for new purchases.');
        }
        
        if ($couponUsage === 'renewals' && $orderType !== Order::TYPE_REN) {
            throw new DisplayException('Coupons can only be used for renewals.');
        }
    }

    /**
     * Validate that a node can accept deployments of a specific type.
     * 
     * @param int $nodeId The node ID to validate
     * @param bool $isFreeProduct Whether this is a free product
     * @throws DisplayException if the node cannot accept the deployment
     */
    public function validateNodeDeployment(int $nodeId, bool $isFreeProduct): void
    {
        $node = Node::findOrFail($nodeId);

        if ($isFreeProduct) {
            if (!$node->deployable_free) {
                throw new DisplayException('Free servers cannot be deployed to this node.');
            }
        } else {
            if (!$node->deployable) {
                throw new DisplayException('Paid servers cannot be deployed to this node.');
            }
        }
    }

    /**
     * Validate and return the egg ID for a product.
     * 
     * @param Product $product The product being purchased
     * @param int|null $requestedEggId The egg ID requested by the user (optional)
     * @return int The validated egg ID to use
     * @throws DisplayException if the requested egg is not allowed
     */
    public function validateAndGetEggId(Product $product, ?int $requestedEggId): int
    {
        $allowedEggs = $product->category->getAllowedEggs();
        
        if ($requestedEggId) {
            if (!in_array($requestedEggId, $allowedEggs)) {
                throw new DisplayException('The selected egg is not allowed for this product category.');
            }
            return $requestedEggId;
        }

        // Default to the category's default egg if none selected
        return $product->category->getDefaultEggId();
    }

    /**
     * Calculate the final price after applying a coupon.
     * 
     * @param Product $product The product being purchased
     * @param int|null $couponId The coupon ID to apply (optional)
     * @param string $orderType The order type (default: Order::TYPE_NEW)
     * @return array{finalPrice: float, discount: float} The final price and discount amount
     */
    public function calculatePriceWithCoupon(Product $product, ?int $couponId, string $orderType = Order::TYPE_NEW): array
    {
        $finalPrice = $product->price;
        $discount = 0.0;
        
        // Only apply coupon if coupons are allowed for this order type
        if ($couponId && $this->areCouponsAllowedForOrderType($orderType)) {
            $coupon = Coupon::find($couponId);
            if ($coupon) {
                $discount = $coupon->calculateDiscount($product->price);
                $finalPrice = max(0, $product->price - $discount);
            }
        }

        return [
            'finalPrice' => $finalPrice,
            'discount' => $discount,
        ];
    }

    /**
     * Validate that the final price matches the expected billing type.
     * 
     * @param float $finalPrice The final price after discounts
     * @param bool $expectFree Whether we expect the price to be free
     * @throws DisplayException if the price doesn't match expectations
     */
    public function validatePriceType(float $finalPrice, bool $expectFree): void
    {
        $isFree = (float) $finalPrice === 0.0;

        if ($expectFree && !$isFree) {
            throw new DisplayException('This product is not free. Please use the payment process.');
        }

        if (!$expectFree && $isFree) {
            throw new DisplayException('This order total is $0. Please use the free order process instead of payment.');
        }
    }

    /**
     * Validate that a user doesn't already own a free product.
     * This only applies to originally free products (not made free by coupon).
     * 
     * @param int $userId The user ID
     * @param Product $product The product being purchased
     * @throws DisplayException if user already owns a free product
     */
    public function validateFreeProductOwnership(int $userId, Product $product): void
    {
        // Only check for originally free products
        if ((float) $product->price === 0.0) {
            $existingCount = Server::where('owner_id', $userId)
                ->where('billing_product_id', $product->id)
                ->count();

            if ($existingCount > 0) {
                throw new DisplayException('You already own one of this free product. Nice try!');
            }
        }
    }
}
