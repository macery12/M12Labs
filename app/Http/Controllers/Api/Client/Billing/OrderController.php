<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Illuminate\Http\Request;
use Everest\Models\Billing\Order;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Database\Eloquent\Builder;
use Everest\Transformers\Api\Client\OrderTransformer;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Exceptions\Http\QueryValueOutOfRangeHttpException;

class OrderController extends ClientApiController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get all orders.
     */
    public function index(Request $request): array
    {
        $perPage = (int) $request->query('per_page', '20');
        if ($perPage < 1 || $perPage > 100) {
            throw new QueryValueOutOfRangeHttpException('per_page', 1, 100);
        }

        $orders = QueryBuilder::for(Order::query()->where('user_id', $request->user()->id)->with('server'))
            ->allowedFilters([
                'id', 'name', 'payment_processor', 'status', 'type',
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    $query->where(function ($q) use ($value) {
                        $q->where('id', 'LIKE', "%{$value}%")
                          ->orWhere('name', 'LIKE', "%{$value}%")
                          ->orWhere('description', 'LIKE', "%{$value}%")
                          ->orWhereHas('server', function ($q) use ($value) {
                              $q->where('name', 'LIKE', "%{$value}%");
                          });
                    });
                }),
                AllowedFilter::callback('min_amount', function (Builder $query, $value) {
                    $query->where('total', '>=', $value);
                }),
                AllowedFilter::callback('max_amount', function (Builder $query, $value) {
                    $query->where('total', '<=', $value);
                }),
                AllowedFilter::callback('start_date', function (Builder $query, $value) {
                    $query->where('created_at', '>=', $value);
                }),
                AllowedFilter::callback('end_date', function (Builder $query, $value) {
                    $query->where('created_at', '<=', $value);
                }),
            ])
            ->allowedSorts(['id', 'name', 'total', 'is_renewal', 'created_at', 'threat_index'])
            ->paginate($perPage);

        return $this->fractal->collection($orders)
            ->transformWith(OrderTransformer::class)
            ->toArray();
    }

    /**
     * Return the data regarding a specific order.
     */
    public function view(Request $request, int $id): array
    {
        $order = Order::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();

        return $this->fractal->item($order)
            ->transformWith(OrderTransformer::class)
            ->toArray();
    }
}
