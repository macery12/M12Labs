<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Illuminate\Http\Request;
use Everest\Models\Billing\Order;
use Spatie\QueryBuilder\QueryBuilder;
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

        $query = Order::query()->where('user_id', $request->user()->id)->with('server');
        
        // Handle search across multiple fields
        if ($request->has('filter.search')) {
            $search = $request->input('filter.search');
            $query->where(function($q) use ($search) {
                $q->where('id', 'LIKE', "%{$search}%")
                  ->orWhere('name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%")
                  ->orWhereHas('server', function($q) use ($search) {
                      $q->where('name', 'LIKE', "%{$search}%");
                  });
            });
        }
        
        // Handle amount range
        if ($request->has('filter.min_amount')) {
            $query->where('total', '>=', $request->input('filter.min_amount'));
        }
        if ($request->has('filter.max_amount')) {
            $query->where('total', '<=', $request->input('filter.max_amount'));
        }
        
        // Handle date range
        if ($request->has('filter.start_date')) {
            $query->where('created_at', '>=', $request->input('filter.start_date'));
        }
        if ($request->has('filter.end_date')) {
            $query->where('created_at', '<=', $request->input('filter.end_date'));
        }

        $orders = QueryBuilder::for($query)
            ->allowedFilters(['id', 'name', 'payment_processor', 'status', 'type', 'start_date', 'end_date', 'min_amount', 'max_amount', 'search'])
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
