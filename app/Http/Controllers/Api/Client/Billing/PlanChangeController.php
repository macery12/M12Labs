<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Illuminate\Http\JsonResponse;
use Everest\Models\Server;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Services\Billing\PlanChangeService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Transformers\Api\Client\ProductTransformer;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Http\Requests\Api\Client\Servers\GetServerRequest;

class PlanChangeController extends ClientApiController
{
    public function __construct(
        private PlanChangeService $planChangeService,
        private BillingValidationService $validationService
    ) {
        parent::__construct();
    }

    /**
     * Get all available plans in the same category as the server's current plan.
     * 
     * @param GetServerRequest $request
     * @param Server $server
     * @return array
     */
    public function getAvailablePlans(GetServerRequest $request, Server $server): array
    {
        if (!$server->billing_product_id) {
            throw new DisplayException('This server is not associated with a billing product.');
        }

        $currentProduct = Product::findOrFail($server->billing_product_id);
        
        // Get all products in the same category
        $products = Product::where('category_uuid', $currentProduct->category_uuid)
            ->where('id', '!=', $currentProduct->id)
            ->get();

        return $this->fractal->collection($products)
            ->transformWith(ProductTransformer::class)
            ->toArray();
    }

    /**
     * Validate if a plan change is possible.
     * Returns validation results including any resource violations.
     * 
     * @param GetServerRequest $request
     * @param Server $server
     * @param int $productId
     * @return JsonResponse
     */
    public function validatePlanChange(GetServerRequest $request, Server $server, int $productId): JsonResponse
    {
        $newProduct = Product::findOrFail($productId);
        
        if (!$server->billing_product_id) {
            return response()->json([
                'valid' => false,
                'message' => 'This server is not associated with a billing product.',
            ], 400);
        }

        $currentProduct = Product::find($server->billing_product_id);
        
        // Ensure products are in the same category
        if ($currentProduct && $currentProduct->category_uuid !== $newProduct->category_uuid) {
            return response()->json([
                'valid' => false,
                'message' => 'Cannot change to a plan in a different category.',
            ], 400);
        }

        // Check for resource violations
        $violations = $this->validationService->validatePlanDowngrade($server, $newProduct);
        
        if (!empty($violations)) {
            return response()->json([
                'valid' => false,
                'violations' => $violations,
                'message' => 'Current resource usage exceeds the limits of the selected plan.',
            ]);
        }

        return response()->json([
            'valid' => true,
            'message' => 'Plan change is allowed.',
        ]);
    }

    /**
     * Apply a plan change to the server.
     * 
     * @param GetServerRequest $request
     * @param Server $server
     * @param int $productId
     * @return JsonResponse
     */
    public function changePlan(GetServerRequest $request, Server $server, int $productId): JsonResponse
    {
        $newProduct = Product::findOrFail($productId);
        
        try {
            $updatedServer = $this->planChangeService->changePlan($server, $newProduct);
            
            return response()->json([
                'success' => true,
                'message' => 'Plan changed successfully.',
                'server' => [
                    'id' => $updatedServer->id,
                    'uuid' => $updatedServer->uuid,
                    'billing_product_id' => $updatedServer->billing_product_id,
                    'limits' => [
                        'memory' => $updatedServer->memory,
                        'disk' => $updatedServer->disk,
                        'cpu' => $updatedServer->cpu,
                        'database' => $updatedServer->database_limit,
                        'backup' => $updatedServer->backup_limit,
                        'allocation' => $updatedServer->allocation_limit,
                    ],
                ],
            ]);
        } catch (DisplayException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
