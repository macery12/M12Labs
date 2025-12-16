<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use Everest\Models\Billing\Coupon;
use Everest\Exceptions\DisplayException;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Http\Requests\Api\Client\Billing\ValidateCouponRequest;

class CouponController extends ClientApiController
{
    /**
     * Validate a coupon code for use in checkout.
     */
    public function validate(ValidateCouponRequest $request): JsonResponse
    {
        $code = Str::upper($request->input('code'));
        $subtotal = (float) $request->input('subtotal');
        $userId = $request->user()->id;

        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon) {
            throw new DisplayException('Invalid coupon code.');
        }

        $validation = $coupon->canBeUsed($userId, $subtotal);

        if (!$validation['valid']) {
            throw new DisplayException($validation['message']);
        }

        $discount = $coupon->calculateDiscount($subtotal);
        $total = max(0, $subtotal - $discount);

        return response()->json([
            'valid' => true,
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => $coupon->value,
            ],
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
        ]);
    }
}
