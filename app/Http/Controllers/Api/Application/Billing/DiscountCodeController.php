<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Illuminate\Http\Response;
use Spatie\QueryBuilder\QueryBuilder;
use Everest\Models\Billing\DiscountCode;
use Everest\Exceptions\Http\QueryValueOutOfRangeHttpException;
use Everest\Transformers\Api\Application\DiscountCodeTransformer;
use Everest\Services\Billing\DiscountCodes\DiscountCodeUpdateService;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Services\Billing\DiscountCodes\DiscountCodeCreationService;
use Everest\Http\Requests\Api\Application\Billing\DiscountCodes\GetDiscountCodesRequest;
use Everest\Http\Requests\Api\Application\Billing\DiscountCodes\StoreDiscountCodeRequest;
use Everest\Http\Requests\Api\Application\Billing\DiscountCodes\DeleteDiscountCodeRequest;

class DiscountCodeController extends ApplicationApiController
{
    /**
     * DiscountCodeController constructor.
     */
    public function __construct(
        private DiscountCodeCreationService $creationService,
        private DiscountCodeUpdateService $updateService,
    ) {
        parent::__construct();
    }

    /**
     * Get all discount codes in the database.
     */
    public function index(GetDiscountCodesRequest $request): array
    {
        $perPage = (int) $request->query('per_page', '20');
        if ($perPage < 1 || $perPage > 100) {
            throw new QueryValueOutOfRangeHttpException('per_page', 1, 100);
        }

        $discount_codes = QueryBuilder::for(DiscountCode::query())
            ->allowedFilters(['id', 'code', 'type', 'expires_at'])
            ->allowedSorts(['id', 'code', 'value', 'type', 'uses', 'expires_at', 'created_at'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->transform($discount_codes, DiscountCodeTransformer::class);
    }

    /**
     * Create a new discount code and store it in the database.
     */
    public function store(StoreDiscountCodeRequest $request): array
    {
        $discount_code = $this->creationService->handle($request->validated());

        return $this->transform($discount_code, DiscountCodeTransformer::class);
    }

    /**
     * Update an existing discount code and store it in the database.
     */
    public function update(int $id, StoreDiscountCodeRequest $request): array
    {
        $discount_code = DiscountCode::findOrFail($id);
        $new_discount_code = $this->updateService->handle($discount_code, $request->validated());

        return $this->transform($new_discount_code, DiscountCodeTransformer::class);
    }

    /**
     * Delete an existing discount code and store it in the database.
     */
    public function delete(int $id, DeleteDiscountCodeRequest $request): Response
    {
        $discount_code = DiscountCode::findOrFail($id);

        $discount_code->delete();

        return $this->returnNoContent();
    }
}
