<?php

namespace Everest\Services\Billing;

use Everest\Models\Node;
use Everest\Models\User;
use Everest\Models\Server;
use Everest\Models\Billing\Coupon;
use Everest\Models\Billing\Product;
use Illuminate\Support\Facades\Log;
use Everest\Exceptions\DisplayException;
use Everest\Repositories\Wings\DaemonServerRepository;
use Everest\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Centralized validation service for billing operations.
 *
 * This service consolidates all billing-related validation logic that was previously
 * duplicated across the old FreeProductController and PaymentController (now replaced by CheckoutController).
 */
class BillingValidationService
{
    /**
     * BillingValidationService constructor.
     *
     * @param DaemonServerRepository $daemonRepository Repository for interacting with Wings daemon
     */
    public function __construct(
        private DaemonServerRepository $daemonRepository,
        private NodeAvailabilityService $nodeAvailabilityService,
    )
    {
    }

    /**
     * Validate node selection for a product before checkout processing.
     *
     * @throws DisplayException
     */
    public function validateNodeSelectionForProduct(?int $nodeId, Product $product): void
    {
        $availableNodes = $this->nodeAvailabilityService->getAvailableNodesForProduct($product);

        if ($availableNodes->isEmpty()) {
            throw new DisplayException('No nodes are available for this product. Please contact support.');
        }

        if (empty($nodeId)) {
            throw new DisplayException('Please select a node to continue.');
        }

        $availableNodeIds = $availableNodes->pluck('id')->map(static fn ($id) => (int) $id)->all();
        if (!in_array((int) $nodeId, $availableNodeIds, true)) {
            throw new DisplayException('The selected node is not available for this product.');
        }
    }

    /**
     * Validate that the billing module is enabled.
     *
     * @throws DisplayException if billing is disabled
     */
    public function validateBillingEnabled(): void
    {
        if (!config('modules.billing.enabled')) {
            throw new DisplayException('The billing module is not enabled.');
        }
    }

    /**
     * Validate that a node can accept deployments of a specific type.
     *
     * @param int $nodeId The node ID to validate
     * @param bool $isFreeProduct Whether this is a free product
     *
     * @throws DisplayException if the node cannot accept the deployment
     */
    public function validateNodeDeployment(int $nodeId, bool $isFreeProduct): void
    {
        $node = Node::findOrFail($nodeId);

        if ($isFreeProduct) {
            if (!$node->deployable_free) {
                throw new DisplayException('Free servers cannot be deployed to this node.');
            }
        } else {
            if (!$node->deployable) {
                throw new DisplayException('Paid servers cannot be deployed to this node.');
            }
        }
    }

    /**
     * Validate and return the egg ID for a product.
     *
     * @param Product $product The product being purchased
     * @param int|null $requestedEggId The egg ID requested by the user (optional)
     *
     * @return int The validated egg ID to use
     *
     * @throws DisplayException if the requested egg is not allowed
     */
    public function validateAndGetEggId(Product $product, ?int $requestedEggId): int
    {
        $allowedEggs = $product->category->getAllowedEggs();

        if ($requestedEggId) {
            if (!in_array($requestedEggId, $allowedEggs)) {
                throw new DisplayException('The selected egg is not allowed for this product category.');
            }

            return $requestedEggId;
        }

        // Default to the category's default egg if none selected
        return $product->category->getDefaultEggId();
    }

    /**
     * Calculate the final price after applying a coupon.
     *
     * SECURITY: This method now validates coupon eligibility including:
     * - Active status
     * - Expiration
     * - Max uses (global and per-user)
     * - Minimum order total
     * - Order type (new/renewal)
     *
     * @param Product $product The product being purchased
     * @param int|null $couponId The coupon ID to apply (optional)
     * @param string $orderType The order type (default: 'new')
     * @param int|null $billingDays The billing cycle days (optional, defaults to 30)
     * @param int|null $nodeId The node ID for location-based pricing (optional)
     * @param int|null $userId The user ID for per-user coupon validation (required if coupon is used)
     *
     * @return array{finalPrice: float, discount: float} The final price and discount amount
     *
     * @throws DisplayException if coupon validation fails
     */
    public function calculatePriceWithCoupon(Product $product, ?int $couponId, string $orderType = 'new', ?int $billingDays = null, ?int $nodeId = null, ?int $userId = null): array
    {
        // Use billing days if provided, otherwise default to 30
        $days = $billingDays ?? 30;

        // Calculate price based on billing cycle and node
        $priceInfo = $product->calculatePrice($days, $nodeId);
        $basePrice = $priceInfo['price'];

        $finalPrice = round($basePrice, 2);
        $discount = 0.0;

        if ($couponId) {
            $coupon = Coupon::find($couponId);
            
            if (!$coupon) {
                throw new DisplayException('The specified coupon does not exist.');
            }

            // SECURITY FIX: Validate coupon eligibility before applying
            if ($userId === null) {
                throw new DisplayException('User ID is required for coupon validation.');
            }

            // Check if coupon can be used
            $validation = $coupon->canBeUsed($userId, $basePrice);
            if (!$validation['valid']) {
                throw new DisplayException($validation['message']);
            }

            // Check if coupon is allowed for this order type
            if (!$coupon->isAllowedForOrderType($orderType)) {
                $allowedTypes = [
                    Coupon::ALLOWED_FOR_PURCHASES => 'new purchases',
                    Coupon::ALLOWED_FOR_RENEWALS => 'renewals',
                ];
                $allowed = $allowedTypes[$coupon->allowed_for] ?? $coupon->allowed_for;
                throw new DisplayException("This coupon is only valid for {$allowed}.");
            }

            $discount = round($coupon->calculateDiscount($basePrice), 2);
            $finalPrice = max(0, round($basePrice - $discount, 2));
        }

        return [
            'finalPrice' => $finalPrice,
            'discount' => $discount,
            'subtotal' => $basePrice,
            'billingDays' => $days,
            'multiplier' => $priceInfo['multiplier'],
            'nodeMultiplier' => $priceInfo['node_multiplier'],
        ];
    }

    /**
     * Validate that the final price matches the expected billing type.
     *
     * @param float $finalPrice The final price after discounts
     * @param bool $expectFree Whether we expect the price to be free
     *
     * @throws DisplayException if the price doesn't match expectations
     */
    public function validatePriceType(float $finalPrice, bool $expectFree): void
    {
        // Treat very small residuals as free and clamp negatives to zero
        $normalizedPrice = max(0, (float) $finalPrice);
        $isFree = $normalizedPrice <= 0.0001;

        if ($expectFree && !$isFree) {
            throw new DisplayException('This product is not free. Please use the payment process.');
        }

        if (!$expectFree && $isFree) {
            throw new DisplayException('This order total is $0. Please use the free order process instead of payment.');
        }
    }

    /**
     * Validate that a user doesn't already own a free product.
     * This only applies to originally free products (not made free by coupon).
     *
     * @param int $userId The user ID
     * @param Product $product The product being purchased
     *
     * @throws DisplayException if user already owns a free product
     */
    public function validateFreeProductOwnership(int $userId, Product $product): void
    {
        // Only check for originally free products
        if ((float) $product->price === 0.0) {
            $existingCount = Server::where('owner_id', $userId)
                ->where('billing_product_id', $product->id)
                ->count();

            if ($existingCount > 0) {
                throw new DisplayException('You already own one of this free product. Nice try!');
            }
        }
    }

    /**
     * Validate that a server can be downgraded to a new product plan.
     * Checks current resource usage against the new plan's limits.
     *
     * @param Server $server The server to validate
     * @param Product $newProduct The product plan to switch to
     *
     * @return array Empty array if validation passes, or array of exceeded resources with details
     */
    public function validatePlanDowngrade(Server $server, Product $newProduct): array
    {
        $violations = [];

        // Get current resource usage from the daemon
        try {
            $stats = $this->daemonRepository->setServer($server)->getDetails();
            $currentUsage = $stats['utilization'] ?? [];
        } catch (DaemonConnectionException $e) {
            // If we can't get stats from Wings, we'll only check against current allocations
            // This ensures we can still validate even if the server is offline
            Log::warning('Could not retrieve server stats for plan validation', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
            $currentUsage = [];
        }

        // Check disk usage (in bytes)
        if (isset($currentUsage['disk_bytes'])) {
            $currentDiskMB = round($currentUsage['disk_bytes'] / 1024 / 1024);
            if ($currentDiskMB > $newProduct->disk_limit) {
                $violations['disk'] = [
                    'current' => $currentDiskMB,
                    'limit' => $newProduct->disk_limit,
                    'unit' => 'MB',
                ];
            }
        }

        // Check memory usage (in bytes)
        if (isset($currentUsage['memory_bytes'])) {
            $currentMemoryMB = round($currentUsage['memory_bytes'] / 1024 / 1024);
            if ($currentMemoryMB > $newProduct->memory_limit) {
                $violations['memory'] = [
                    'current' => $currentMemoryMB,
                    'limit' => $newProduct->memory_limit,
                    'unit' => 'MB',
                ];
            }
        }

        // Check CPU (percentage)
        if (isset($currentUsage['cpu_absolute']) && $newProduct->cpu_limit > 0) {
            $currentCpuPercent = round($currentUsage['cpu_absolute']);
            if ($currentCpuPercent > $newProduct->cpu_limit) {
                $violations['cpu'] = [
                    'current' => $currentCpuPercent,
                    'limit' => $newProduct->cpu_limit,
                    'unit' => '%',
                ];
            }
        }

        // Check database count
        $currentDatabases = $server->databases()->count();
        if ($currentDatabases > $newProduct->database_limit) {
            $violations['databases'] = [
                'current' => $currentDatabases,
                'limit' => $newProduct->database_limit,
                'unit' => 'databases',
            ];
        }

        // Check backup count
        $currentBackups = $server->backups()->count();
        if ($currentBackups > $newProduct->backup_limit) {
            $violations['backups'] = [
                'current' => $currentBackups,
                'limit' => $newProduct->backup_limit,
                'unit' => 'backups',
            ];
        }

        // Check allocation count
        $currentAllocations = $server->allocations()->count();
        if ($currentAllocations > $newProduct->allocation_limit) {
            $violations['allocations'] = [
                'current' => $currentAllocations,
                'limit' => $newProduct->allocation_limit,
                'unit' => 'allocations',
            ];
        }

        return $violations;
    }
}
