<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\BillingCycle;
use Everest\Services\Billing\BillingCycleService;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;

class BillingCycleController extends ApplicationApiController
{
    public function __construct(private BillingCycleService $billingCycleService)
    {
        parent::__construct();
    }

    /**
     * Get all billing cycles for a product with calculated prices.
     */
    public function index(Request $request, int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $cycles = $this->billingCycleService->getAvailableCycles($product);

        return response()->json(['data' => $cycles]);
    }

    /**
     * Sync billing cycles for a product.
     */
    public function sync(Request $request, int $productId): Response
    {
        $product = Product::findOrFail($productId);
        
        $validated = $request->validate([
            'cycles' => 'required|array',
            'cycles.*.days' => 'required|integer|min:1|max:365',
            'cycles.*.is_enabled' => 'boolean',
        ]);

        $this->billingCycleService->syncBillingCycles($product, $validated['cycles']);

        return $this->returnNoContent();
    }

    /**
     * Get suggested multiplier ranges.
     */
    public function multiplierRanges(): JsonResponse
    {
        $ranges = $this->billingCycleService->getSuggestedMultiplierRanges();
        return response()->json(['data' => $ranges]);
    }

    /**
     * Delete a specific billing cycle.
     */
    public function delete(int $productId, int $cycleId): Response
    {
        $cycle = BillingCycle::where('product_id', $productId)
            ->where('id', $cycleId)
            ->firstOrFail();

        $cycle->delete();

        return $this->returnNoContent();
    }
}
