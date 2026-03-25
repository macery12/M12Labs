<?php

namespace Everest\Services\Billing;

use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Models\Billing\DiscountCode;

class DiscountService
{
    /**
     * If a discount code is valid, subtract it from the total product price.
     */
    public function handle(Product $product, string $code): float
    {
        $discount_code = DiscountCode::where('code', $code)->first();

        if (!$discount_code) {
            throw new DisplayException('The selected discount code does not exist.');
        }

        if (!$discount_code->isValid()) {
            throw new DisplayException('The selected discount code is invalid.');
        }

        return match($discount_code->type) {
            'percentage' => max(0, $product->price - ($product->price * $discount_code->value / 100)),
            'numeric' => max(0, $product->price - $discount_code->value),
            default => throw new DisplayException('The discount code has an unknown type.'),
        };
    }
}
