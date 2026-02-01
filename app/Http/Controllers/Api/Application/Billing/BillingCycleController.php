<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Models\Billing\BillingCycle;
use Spatie\QueryBuilder\QueryBuilder;
use Everest\Transformers\Api\Application\BillingCycleTransformer;
use Everest\Exceptions\Http\QueryValueOutOfRangeHttpException;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Http\Requests\Api\Application\Billing\BillingCycles\GetBillingCyclesRequest;
use Everest\Http\Requests\Api\Application\Billing\BillingCycles\GetBillingCycleRequest;
use Everest\Http\Requests\Api\Application\Billing\BillingCycles\StoreBillingCycleRequest;
use Everest\Http\Requests\Api\Application\Billing\BillingCycles\UpdateBillingCycleRequest;
use Everest\Http\Requests\Api\Application\Billing\BillingCycles\DeleteBillingCycleRequest;

class BillingCycleController extends ApplicationApiController
{
    /**
     * BillingCycleController constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get all billing cycles.
     */
    public function index(GetBillingCyclesRequest $request): array
    {
        $perPage = (int) $request->query('per_page', '50');
        if ($perPage < 1 || $perPage > 100) {
            throw new QueryValueOutOfRangeHttpException('per_page', 1, 100);
        }

        $cycles = QueryBuilder::for(BillingCycle::query())
            ->allowedFilters(['id', 'name', 'duration_days', 'is_active'])
            ->allowedSorts(['id', 'name', 'duration_days', 'sort_order', 'created_at'])
            ->defaultSort('sort_order')
            ->paginate($perPage);

        return $this->fractal->collection($cycles)
            ->transformWith(BillingCycleTransformer::class)
            ->toArray();
    }

    /**
     * Get a specific billing cycle.
     */
    public function view(GetBillingCycleRequest $request, BillingCycle $cycle): array
    {
        return $this->fractal->item($cycle)
            ->transformWith(BillingCycleTransformer::class)
            ->toArray();
    }

    /**
     * Store a new billing cycle.
     */
    public function store(StoreBillingCycleRequest $request): JsonResponse
    {
        try {
            $cycle = BillingCycle::create([
                'name' => $request->input('name'),
                'duration_days' => $request->input('durationDays'),
                'sort_order' => $request->input('sortOrder', 0),
                'is_active' => $request->input('isActive', true),
            ]);

            Activity::event('admin:billing:billing_cycle:create')
                ->subject($cycle)
                ->property(['name' => $cycle->name, 'duration_days' => $cycle->duration_days])
                ->log();

            return new JsonResponse(
                $this->fractal->item($cycle)
                    ->transformWith(BillingCycleTransformer::class)
                    ->toArray(),
                Response::HTTP_CREATED
            );
        } catch (\Exception $ex) {
            throw new \Exception('Failed to create billing cycle: ' . $ex->getMessage());
        }
    }

    /**
     * Update a billing cycle.
     */
    public function update(UpdateBillingCycleRequest $request, BillingCycle $cycle): array
    {
        $cycle->update([
            'name' => $request->input('name', $cycle->name),
            'duration_days' => $request->input('durationDays', $cycle->duration_days),
            'sort_order' => $request->input('sortOrder', $cycle->sort_order),
            'is_active' => $request->input('isActive', $cycle->is_active),
        ]);

        Activity::event('admin:billing:billing_cycle:update')
            ->subject($cycle)
            ->property(['name' => $cycle->name, 'duration_days' => $cycle->duration_days])
            ->log();

        return $this->fractal->item($cycle->fresh())
            ->transformWith(BillingCycleTransformer::class)
            ->toArray();
    }

    /**
     * Delete a billing cycle.
     */
    public function destroy(DeleteBillingCycleRequest $request, BillingCycle $cycle): Response
    {
        // Check if cycle is in use
        $productCount = $cycle->products()->count();
        if ($productCount > 0) {
            throw new \Exception("Cannot delete billing cycle: it is associated with {$productCount} product(s)");
        }

        Activity::event('admin:billing:billing_cycle:delete')
            ->subject($cycle)
            ->property(['name' => $cycle->name])
            ->log();

        $cycle->delete();

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
