<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
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
    public function index(Request $request, int $category, int $product): JsonResponse
    {
        \Log::info('BillingCycleController::index called', [
            'category_param' => $category,
            'product_param' => $product,
        ]);

        $productModel = Product::findOrFail($product);
        // Use getAllCycles for admin to include is_enabled status
        $cycles = $this->billingCycleService->getAllCycles($productModel);

        return response()->json(['data' => $cycles]);
    }

    /**
     * Sync billing cycles for a product.
     */
    public function sync(Request $request, int $category, int $product): Response
    {
        \Log::info('BillingCycleController::sync called', [
            'category_param' => $category,
            'product_param' => $product,
            'request_url' => $request->fullUrl(),
            'request_path' => $request->path(),
            'route_params' => $request->route()->parameters(),
        ]);

        $productModel = Product::findOrFail($product);

        \Log::info('Product loaded', [
            'product_id' => $productModel->id,
            'product_name' => $productModel->name,
        ]);

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
    public function delete(int $category, int $product, int $cycle): Response
    {
        \Log::info('BillingCycleController::delete called', [
            'category_param' => $category,
            'product_param' => $product,
            'cycle_param' => $cycle,
        ]);

        $cycleModel = BillingCycle::where('product_id', $product)
            ->where('id', $cycle)
            ->firstOrFail();

        $cycleModel->delete();

        return $this->returnNoContent();
    }
}
