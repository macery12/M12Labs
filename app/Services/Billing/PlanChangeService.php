<?php

namespace Everest\Services\Billing;

use Everest\Models\Server;
use Illuminate\Support\Facades\DB;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Services\Servers\BuildModificationService;

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
     * Anti-abuse measures:
     * - Configurable cooldown between plan changes (admin settings)
     * - Must have active renewal date
     * - Resource usage validation on downgrades
     *
     * @param Server $server The server to change
     * @param Product $newProduct The new product/plan to switch to
     * @param bool $force Whether to force the change even if resources are being reduced
     * @param int|null $billingDays Optional billing cycle in days (if null, keeps current billing_days)
     * @return Server The updated server
     *
     * @throws DisplayException if the plan change is not allowed
     */
    public function changePlan(Server $server, Product $newProduct, bool $force = false, ?int $billingDays = null): Server
    {
        // Ensure the new product is in the same category as the current one
        $currentProduct = $server->billingProductId ? Product::find($server->billingProductId) : null;

        if ($currentProduct && $currentProduct->category_uuid !== $newProduct->category_uuid) {
            throw new DisplayException('Cannot change to a plan in a different category.');
        }

        // Prevent plan changes if server has no renewal date (avoids state issues)
        if (!$server->renewal_date) {
            throw new DisplayException('Server must have a renewal date to change plans.');
        }

        // Enforce cooldown period to prevent rapid plan switching abuse
        // Users cannot switch plans more than once within the configured cooldown period
        $cooldownHours = (int) \Everest\Models\Setting::get('settings::modules:billing:plan_change_cooldown_hours', 72);

        // Skip cooldown check if set to 0 (disabled)
        if ($cooldownHours > 0 && $server->last_plan_change_at) {
            $hoursSinceLastChange = \Carbon\Carbon::parse($server->last_plan_change_at)->diffInHours(\Carbon\Carbon::now());

            if ($hoursSinceLastChange < $cooldownHours) {
                $hoursRemaining = $cooldownHours - $hoursSinceLastChange;
                throw new DisplayException("Plan changes are limited to once every {$cooldownHours} hours. Please wait {$hoursRemaining} more hours before changing plans again.");
            }
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
                throw new DisplayException('Cannot downgrade: Current usage exceeds new plan limits. ' . implode('; ', $errors));
            }
        }

        // Update server resources to match the new product
        return DB::transaction(function () use ($server, $newProduct, $billingDays) {
            // Update the billing product ID and track the change time
            $server->billing_product_id = $newProduct->id;
            $server->last_plan_change_at = \Carbon\Carbon::now();
            
            // Update billing_days if provided
            if ($billingDays !== null) {
                $server->billing_days = $billingDays;
            }
            
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
     *
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
