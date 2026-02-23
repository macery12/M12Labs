<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Everest\Models\Node;
use Illuminate\Http\Request;
use Everest\Facades\Activity;
use Illuminate\Http\JsonResponse;
use Everest\Exceptions\DisplayException;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;

class NodePricingController extends ApplicationApiController
{
    /**
     * Get all nodes with their pricing multipliers.
     */
    public function index(): JsonResponse
    {
        $nodes = Node::select('id', 'name', 'price_multiplier', 'price_multiplier_description', 'deployable', 'deployable_free')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $nodes->map(function ($node) {
                return [
                    'id' => $node->id,
                    'name' => $node->name,
                    'price_multiplier' => $node->price_multiplier ?? 1.0,
                    'price_multiplier_description' => $node->price_multiplier_description,
                    'deployable' => $node->deployable,
                    'deployable_free' => $node->deployable_free,
                ];
            }),
        ]);
    }

    /**
     * Update a node's pricing multiplier.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $node = Node::findOrFail($id);

        // Validate multiplier
        $multiplier = $request->input('price_multiplier');
        $description = $this->normalizeDescription($request->input('price_multiplier_description'));

        if ($multiplier === null) {
            throw new DisplayException('Price multiplier is required.');
        }

        $multiplier = (float) $multiplier;

        if ($multiplier < 0.0 || $multiplier > 5.0) {
            throw new DisplayException('Price multiplier must be between 0.00 and 5.00.');
        }

        $oldMultiplier = $node->price_multiplier;
        $node->price_multiplier = $multiplier;
        $node->price_multiplier_description = $description;
        $node->save();

        Activity::event('admin:billing:node-pricing:update')
            ->property('node_id', $node->id)
            ->property('node_name', $node->name)
            ->property('old_multiplier', $oldMultiplier)
            ->property('new_multiplier', $multiplier)
            ->description("Updated pricing multiplier for node '{$node->name}' from {$oldMultiplier}x to {$multiplier}x")
            ->log();

        return response()->json([
            'data' => [
                'id' => $node->id,
                'name' => $node->name,
                'price_multiplier' => $node->price_multiplier,
                'price_multiplier_description' => $node->price_multiplier_description,
            ],
        ]);
    }

    /**
     * Update multiple node pricing multipliers at once.
     */
    public function batchUpdate(Request $request): JsonResponse
    {
        $updates = $request->input('nodes', []);

        if (!is_array($updates)) {
            throw new DisplayException('Invalid request format. Expected array of nodes.');
        }

        $updatedNodes = [];

        foreach ($updates as $update) {
            if (!isset($update['id']) || !isset($update['price_multiplier'])) {
                continue;
            }

            $node = Node::find($update['id']);
            if (!$node) {
                continue;
            }

            $multiplier = (float) $update['price_multiplier'];
            $description = array_key_exists('price_multiplier_description', $update)
                ? $this->normalizeDescription($update['price_multiplier_description'])
                : $node->price_multiplier_description;

            if ($multiplier < 0.0 || $multiplier > 5.0) {
                throw new DisplayException("Price multiplier for node '{$node->name}' must be between 0.00 and 5.00.");
            }

            $oldMultiplier = $node->price_multiplier;
            $oldDescription = $node->price_multiplier_description;
            $node->price_multiplier = $multiplier;
            $node->price_multiplier_description = $description;
            $node->save();

            $updatedNodes[] = [
                'id' => $node->id,
                'name' => $node->name,
                'old_multiplier' => $oldMultiplier,
                'new_multiplier' => $multiplier,
                'old_description' => $oldDescription,
                'new_description' => $description,
            ];
        }

        Activity::event('admin:billing:node-pricing:batch-update')
            ->property('updates', $updatedNodes)
            ->description('Batch updated pricing multipliers for ' . count($updatedNodes) . ' node(s)')
            ->log();

        return response()->json([
            'data' => $updatedNodes,
        ]);
    }

    /**
     * Reset a node's pricing multiplier to default (1.00).
     */
    public function reset(int $id): JsonResponse
    {
        $node = Node::findOrFail($id);
        $oldMultiplier = $node->price_multiplier;
        $node->price_multiplier = 1.0;
        $node->save();

        Activity::event('admin:billing:node-pricing:reset')
            ->property('node_id', $node->id)
            ->property('node_name', $node->name)
            ->property('old_multiplier', $oldMultiplier)
            ->description("Reset pricing multiplier for node '{$node->name}' to 1.00x")
            ->log();

        return response()->json([
            'data' => [
                'id' => $node->id,
                'name' => $node->name,
                'price_multiplier' => $node->price_multiplier,
                'price_multiplier_description' => $node->price_multiplier_description,
            ],
        ]);
    }

    /**
     * Reset all nodes' pricing multipliers to default (1.00).
     */
    public function resetAll(): JsonResponse
    {
        $nodes = Node::all();
        $resetCount = 0;

        foreach ($nodes as $node) {
            if ($node->price_multiplier != 1.0) {
                $node->price_multiplier = 1.0;
                $node->save();
                ++$resetCount;
            }
        }

        Activity::event('admin:billing:node-pricing:reset-all')
            ->property('reset_count', $resetCount)
            ->description("Reset pricing multipliers for {$resetCount} node(s) to 1.00x")
            ->log();

        return response()->json([
            'data' => [
                'reset_count' => $resetCount,
            ],
        ]);
    }

    /**
     * Normalize and validate the provided price multiplier description.
     */
    protected function normalizeDescription(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }

        if (!is_string($description)) {
            throw new DisplayException('Price description must be a string.');
        }

        $trimmed = trim($description);

        if ($trimmed === '') {
            return null;
        }

        if (strlen($trimmed) > 500) {
            throw new DisplayException('Price description must be 500 characters or fewer.');
        }

        return $trimmed;
    }
}
