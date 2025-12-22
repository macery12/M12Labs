<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
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
    public function validateCoupon(ValidateCouponRequest $request): JsonResponse
    {
        try {
            // Check if coupons are enabled
            if (!config('modules.billing.coupons_enabled', true)) {
                throw new DisplayException('Coupons are currently disabled.');
            }

            // Check if coupons table exists
            if (!\Schema::hasTable('coupons')) {
                \Log::error('Coupon validation failed: coupons table does not exist');
                throw new DisplayException('Coupon system is not properly configured. Please contact support.');
            }

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
        } catch (DisplayException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Coupon validation error: ' . $e->getMessage(), [
                'code' => $request->input('code'),
                'subtotal' => $request->input('subtotal'),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new DisplayException('An error occurred while validating the coupon. Please try again later.');
        }
    }
}
