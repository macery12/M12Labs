<?php

namespace Everest\Services\Billing;

use Illuminate\Support\Collection;
use Everest\Models\Node;
use Everest\Models\Billing\Product;
use Everest\Repositories\Wings\DaemonConfigurationRepository;

class NodeAvailabilityService
{
    public function __construct(private DaemonConfigurationRepository $repository)
    {
    }

    /**
     * Get all deployable and currently available nodes for a product.
     */
    public function getAvailableNodesForProduct(Product $product): Collection
    {
        $isFreeProduct = (float) $product->price === 0.00;

        $nodes = Node::where($isFreeProduct ? 'deployable_free' : 'deployable', true)->get();
        $availableNodes = collect();

        foreach ($nodes as $node) {
            $hasFreeAllocation = $node->allocations()->whereNull('server_id')->exists();
            if (!$hasFreeAllocation) {
                continue;
            }

            try {
                $this->repository->setNode($node)->getSystemInformation();
            } catch (\Throwable $e) {
                continue;
            }

            $availableNodes->push($node);
        }

        return $availableNodes;
    }

    /**
     * Determine whether at least one deployable node is currently available for a product.
     */
    public function hasAvailableNodesForProduct(Product $product): bool
    {
        return $this->getAvailableNodesForProduct($product)->isNotEmpty();
    }
}