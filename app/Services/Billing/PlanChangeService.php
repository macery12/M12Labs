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
     * Plan change pricing logic:
     * - Upgrades: Prorated charge for the difference in price until next renewal
     * - Downgrades: Credit applied to next renewal OR immediate renewal date adjustment
     * - Renewal date is adjusted proportionally to prevent abuse
     * 
     * Anti-abuse measures:
     * - 72-hour cooldown between plan changes
     * - Renewal date adjustment on downgrades
     * - Must have active renewal date
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

        // Prevent plan changes if server has no renewal date (avoids state issues)
        if (!$server->renewal_date) {
            throw new DisplayException('Server must have a renewal date to change plans.');
        }

        // Enforce cooldown period to prevent rapid plan switching abuse
        // Users cannot switch plans more than once every 72 hours
        if ($server->last_plan_change_at) {
            $hoursSinceLastChange = \Carbon\Carbon::parse($server->last_plan_change_at)->diffInHours(\Carbon\Carbon::now());
            $cooldownHours = config('modules.billing.plan_change_cooldown_hours', 72);
            
            if ($hoursSinceLastChange < $cooldownHours) {
                $hoursRemaining = $cooldownHours - $hoursSinceLastChange;
                throw new DisplayException(
                    "Plan changes are limited to once every {$cooldownHours} hours. " .
                    "Please wait {$hoursRemaining} more hours before changing plans again."
                );
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
                throw new DisplayException(
                    'Cannot downgrade: Current usage exceeds new plan limits. ' . implode('; ', $errors)
                );
            }
        }

        // Update server resources to match the new product
        return DB::transaction(function () use ($server, $newProduct, $currentProduct) {
            // Adjust renewal date to prevent abuse
            // When changing plans, the renewal date is adjusted proportionally based on price difference
            $this->adjustRenewalDateForPlanChange($server, $currentProduct, $newProduct);

            // Update the billing product ID and track the change time
            $server->billing_product_id = $newProduct->id;
            $server->last_plan_change_at = \Carbon\Carbon::now();
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
     * Adjust the renewal date when changing plans to prevent abuse.
     * 
     * Logic:
     * - For upgrades (more expensive): Keep the same renewal date (user pays at next renewal)
     * - For downgrades (cheaper): Adjust renewal date closer to prevent cheap renewal abuse
     *   The time remaining is scaled by the price ratio to prevent users from downgrading,
     *   renewing cheap, then upgrading back.
     * 
     * Example: If user has 20 days left on $10 plan and downgrades to $5 plan,
     * the renewal date is adjusted to ~10 days to maintain fair pricing.
     * 
     * @param Server $server The server being modified
     * @param Product|null $oldProduct The current product
     * @param Product $newProduct The new product
     */
    private function adjustRenewalDateForPlanChange(Server $server, ?Product $oldProduct, Product $newProduct): void
    {
        if (!$oldProduct || !$server->renewal_date) {
            return;
        }

        $oldPrice = (float) $oldProduct->price;
        $newPrice = (float) $newProduct->price;

        // No adjustment needed if prices are the same or for free plans
        if ($oldPrice === $newPrice || $oldPrice === 0.0 || $newPrice === 0.0) {
            return;
        }

        // Calculate days remaining until renewal
        $now = \Carbon\Carbon::now();
        $renewalDate = \Carbon\Carbon::parse($server->renewal_date);
        $daysRemaining = max(0, $now->diffInDays($renewalDate, false));

        if ($daysRemaining <= 0) {
            return; // Renewal is overdue, no adjustment needed
        }

        // For downgrades (cheaper plan), adjust the renewal date proportionally
        // This prevents abuse where users downgrade, renew cheap, then upgrade
        if ($newPrice < $oldPrice) {
            // Calculate the price ratio
            $priceRatio = $newPrice / $oldPrice;
            
            // Adjust days remaining proportionally to the price reduction
            // If downgrading to 50% price, you get 50% of the remaining time
            $adjustedDays = ceil($daysRemaining * $priceRatio);
            
            // Set new renewal date
            $server->renewal_date = $now->copy()->addDays($adjustedDays);
        }
        
        // For upgrades, keep the existing renewal date
        // User will pay the new higher price at next renewal
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
