<?php

namespace Everest\Services\Billing;

use Everest\Models\Server;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Services\Servers\BuildModificationService;
use Illuminate\Support\Facades\DB;

/**
 * Service to handle plan upgrades and downgrades for servers.
 */
class PlanChangeService
{
    /**
     * PlanChangeService constructor.
     */
    public function __construct(
        private BillingValidationService $validationService,
        private BuildModificationService $buildModificationService
    ) {
    }

    /**
     * Change a server's billing plan to a new product.
     * This validates that the change is allowed and applies all resource changes.
     * 
     * @param Server $server The server to change
     * @param Product $newProduct The new product/plan to switch to
     * @param bool $force Whether to force the change even if resources are being reduced
     * @return Server The updated server
     * @throws DisplayException if the plan change is not allowed
     */
    public function changePlan(Server $server, Product $newProduct, bool $force = false): Server
    {
        // Ensure the new product is in the same category as the current one
        $currentProduct = $server->billingProductId ? Product::find($server->billingProductId) : null;
        
        if ($currentProduct && $currentProduct->category_uuid !== $newProduct->category_uuid) {
            throw new DisplayException('Cannot change to a plan in a different category.');
        }

        // Check if this is a downgrade (any resource is being reduced)
        $isDowngrade = $this->isDowngrade($server, $newProduct);

        // If it's a downgrade and not forced, validate current resource usage
        if ($isDowngrade && !$force) {
            $violations = $this->validationService->validatePlanDowngrade($server, $newProduct);
            
            if (!empty($violations)) {
                // Format error message with all violations
                $errors = [];
                foreach ($violations as $resource => $data) {
                    $errors[] = ucfirst($resource) . ': using ' . $data['current'] . ' ' . $data['unit'] . 
                               ', new limit is ' . $data['limit'] . ' ' . $data['unit'];
                }
                throw new DisplayException(
                    'Cannot downgrade: Current usage exceeds new plan limits. ' . implode('; ', $errors)
                );
            }
        }

        // Update server resources to match the new product
        return DB::transaction(function () use ($server, $newProduct) {
            // Update the billing product ID
            $server->billing_product_id = $newProduct->id;
            $server->save();

            // Apply the new resource limits using BuildModificationService
            $buildData = [
                'memory' => $newProduct->memory_limit,
                'disk' => $newProduct->disk_limit,
                'cpu' => $newProduct->cpu_limit,
                'backup_limit' => $newProduct->backup_limit,
                'database_limit' => $newProduct->database_limit,
                'allocation_limit' => $newProduct->allocation_limit,
            ];

            return $this->buildModificationService->handle($server, $buildData);
        });
    }

    /**
     * Determine if changing to a new product is a downgrade.
     * A downgrade is when any resource limit is being reduced.
     * 
     * Note: This checks configured limits, not actual usage. The validation logic
     * in validatePlanDowngrade() checks actual resource usage and will block downgrades
     * if current usage exceeds the new limits.
     * 
     * @param Server $server The current server
     * @param Product $newProduct The new product to switch to
     * @return bool True if this is a downgrade
     */
    private function isDowngrade(Server $server, Product $newProduct): bool
    {
        return $newProduct->memory_limit < $server->memory ||
               $newProduct->disk_limit < $server->disk ||
               $newProduct->cpu_limit < $server->cpu ||
               $newProduct->database_limit < $server->database_limit ||
               $newProduct->backup_limit < $server->backup_limit ||
               $newProduct->allocation_limit < $server->allocation_limit;
    }
}
