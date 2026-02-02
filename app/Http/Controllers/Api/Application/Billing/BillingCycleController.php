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
    public function index(Request $request, int $product): JsonResponse
    {
        $productModel = Product::findOrFail($product);
        // Use getAllCycles for admin to include is_enabled status
        $cycles = $this->billingCycleService->getAllCycles($productModel);

        return response()->json(['data' => $cycles]);
    }

    /**
     * Sync billing cycles for a product.
     */
    public function sync(Request $request, int $product): Response
    {
        $productModel = Product::findOrFail($product);
        
        $validated = $request->validate([
            'cycles' => 'required|array',
            'cycles.*.days' => 'required|integer|min:1|max:365',
            'cycles.*.is_enabled' => 'boolean',
        ]);

        $this->billingCycleService->syncBillingCycles($productModel, $validated['cycles']);

        return $this->returnNoContent();
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
