<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Models\Billing\Coupon;
use Spatie\QueryBuilder\QueryBuilder;
use Everest\Transformers\Api\Application\CouponTransformer;
use Everest\Exceptions\Http\QueryValueOutOfRangeHttpException;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Http\Requests\Api\Application\Billing\Coupons\GetCouponsRequest;
use Everest\Http\Requests\Api\Application\Billing\Coupons\GetCouponRequest;
use Everest\Http\Requests\Api\Application\Billing\Coupons\StoreCouponRequest;
use Everest\Http\Requests\Api\Application\Billing\Coupons\UpdateCouponRequest;
use Everest\Http\Requests\Api\Application\Billing\Coupons\DeleteCouponRequest;

class CouponController extends ApplicationApiController
{
    /**
     * CouponController constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get all coupons.
     */
    public function index(GetCouponsRequest $request): array
    {
        $perPage = (int) $request->query('per_page', '20');
        if ($perPage < 1 || $perPage > 100) {
            throw new QueryValueOutOfRangeHttpException('per_page', 1, 100);
        }

        $coupons = QueryBuilder::for(Coupon::query())
            ->allowedFilters(['code', 'type', 'is_active'])
            ->allowedSorts(['id', 'code', 'created_at', 'expires_at'])
            ->paginate($perPage);

        return $this->fractal->collection($coupons)
            ->transformWith(CouponTransformer::class)
            ->toArray();
    }

    /**
     * Store a new coupon in the database.
     */
    public function store(StoreCouponRequest $request): JsonResponse
    {
        $coupon = Coupon::create([
            'code' => $request->input('code'),
            'type' => $request->input('type'),
            'value' => (float) $request->input('value'),
            'max_uses' => $request->input('max_uses'),
            'max_uses_per_user' => $request->input('max_uses_per_user'),
            'min_order_total' => $request->input('min_order_total') ? (float) $request->input('min_order_total') : null,
            'expires_at' => $request->input('expires_at'),
            'is_active' => $request->input('is_active', true),
        ]);

        Activity::event('admin:billing:coupons:create')
            ->property('coupon', $coupon)
            ->description('A new coupon was created')
            ->log();

        return $this->fractal->item($coupon)
            ->transformWith(CouponTransformer::class)
            ->respond(Response::HTTP_CREATED);
    }

    /**
     * View an existing coupon.
     */
    public function view(GetCouponRequest $request, Coupon $coupon): array
    {
        return $this->fractal->item($coupon)
            ->transformWith(CouponTransformer::class)
            ->toArray();
    }

    /**
     * Update an existing coupon.
     */
    public function update(UpdateCouponRequest $request, Coupon $coupon): Response
    {
        $coupon->update([
            'code' => $request->input('code', $coupon->code),
            'type' => $request->input('type', $coupon->type),
            'value' => $request->has('value') ? (float) $request->input('value') : $coupon->value,
            'max_uses' => $request->has('max_uses') ? $request->input('max_uses') : $coupon->max_uses,
            'max_uses_per_user' => $request->has('max_uses_per_user') ? $request->input('max_uses_per_user') : $coupon->max_uses_per_user,
            'min_order_total' => $request->has('min_order_total') ? ($request->input('min_order_total') ? (float) $request->input('min_order_total') : null) : $coupon->min_order_total,
            'expires_at' => $request->has('expires_at') ? $request->input('expires_at') : $coupon->expires_at,
            'is_active' => $request->has('is_active') ? $request->input('is_active') : $coupon->is_active,
        ]);

        Activity::event('admin:billing:coupons:update')
            ->property('coupon', $coupon)
            ->property('new_data', $request->validated())
            ->description('A coupon has been updated')
            ->log();

        return $this->returnNoContent();
    }

    /**
     * Delete a coupon.
     */
    public function delete(DeleteCouponRequest $request, Coupon $coupon): Response
    {
        $coupon->delete();

        Activity::event('admin:billing:coupons:delete')
            ->property('coupon', $coupon)
            ->description('A coupon has been deleted')
            ->log();

        return $this->returnNoContent();
    }
}
