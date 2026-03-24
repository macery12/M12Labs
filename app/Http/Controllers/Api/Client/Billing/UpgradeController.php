<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Stripe\StripeClient;
use Everest\Models\Server;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Services\Billing\UpgradeService;
use Everest\Services\Billing\PaymentService;
use Everest\Services\Billing\CreateOrderService;
use Everest\Transformers\Api\Client\ProductTransformer;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Http\Requests\Api\Client\Billing\ProcessUpgradeRequest;
use Everest\Http\Requests\Api\Client\Billing\GetUpgradeChargeRequest;
use Everest\Http\Requests\Api\Client\Billing\GetUpgradeOptionsRequest;

class UpgradeController extends ClientApiController
{
    public function __construct(
        private UpgradeService $upgradeService,
        private PaymentService $paymentService,
        private CreateOrderService $orderService
    ) {
        parent::__construct();

        $this->stripe = new StripeClient(config('modules.billing.keys.secret'));
    }

    /**
     * Returns all available products to upgrade to.
     */
    public function index(GetUpgradeOptionsRequest $request, Server $server): array
    {
        $existing_product = Product::findOrFail($server->billing_product_id);

        $products = Product::where('category_uuid', $existing_product->category->uuid)
            ->where('price', '>', $existing_product->price)
            ->get();

        return $this->fractal->collection($products)
            ->transformWith(ProductTransformer::class)
            ->toArray();
    }

    /**
     * Generate an OTC for the pro-rated server upgrade.
     */
    public function charge(GetUpgradeChargeRequest $request, Server $server): array
    {
        $existing_product = Product::findOrFail($server->billing_product_id);
        $new_product = Product::findOrFail($request->input('product_id'));

        if ($existing_product->price >= $new_product->price) {
            throw new DisplayException('You cannot upgrade to a cheaper plan.');
        };

        $charge = $this->upgradeService->charge($server, $existing_product, $new_product);

        return ['charge' => $charge];
    }

    /**
     * Process a one-time upgrade fee through payment gateway and
     * update the server with new resources according to new package.
     */
    public function create(ProcessUpgradeRequest $request, Server $server): string
    {
        $existing_product = Product::findOrFail($server->billing_product_id);
        $new_product = Product::findOrFail($request->input('product_id'));
        $price = $this->upgradeService->charge($server, $existing_product, $new_product);

        if ($existing_product->price >= $new_product->price) {
            throw new DisplayException('You cannot upgrade to a cheaper plan.');
        };

        $metadata = [
            'user_id' => (string) $request->user()->id,
            'customer_email' => $request->user()->email,
            'product_id' => (string) $new_product->id,
            'server_id' => (string) $server->id,
            'order_type' => Order::TYPE_UPGRADE,
        ];

        $transaction = $this->paymentService->create($this->stripe, $request->user(), $new_product, $metadata, $price);

        $order = $this->orderService->create(
            $transaction->id,
            $request->user(),
            $new_product,
            Order::STATUS_PENDING,
            Order::TYPE_UPGRADE,
            $price,
        )->assignServer($server);

        return $transaction->url;
    }
}
