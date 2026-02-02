<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Everest\Models\Billing\Product;
use Everest\Services\Billing\BillingCycleService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Http\Controllers\Api\Client\ClientApiController;

class BillingCycleController extends ClientApiController
{
    public function __construct(
        private BillingCycleService $billingCycleService,
        private BillingValidationService $validationService,
    ) {
        parent::__construct();
    }

    /**
     * Get available billing cycles for a product with prices.
     * 
     * @param Request $request
     * @param int $id The product ID from the route
     * @return JsonResponse
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        
        // Get billing cycles with calculated prices
        $cycles = $this->billingCycleService->getAvailableCycles($product);

        // Apply coupon if provided
        $couponId = $request->input('coupon_id');
        if ($couponId) {
            foreach ($cycles as &$cycle) {
                // Calculate price with coupon
                $priceInfo = $this->validationService->calculatePriceWithCoupon(
                    $product,
                    (int) $couponId,
                    $request->input('type', 'new'),
                    $cycle['days']
                );
                
                $cycle['price_with_coupon'] = $priceInfo['finalPrice'];
                $cycle['coupon_discount'] = $priceInfo['discount'];
            }
        }

        return response()->json(['data' => $cycles]);
    }
}
