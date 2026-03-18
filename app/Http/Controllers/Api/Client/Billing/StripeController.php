<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Stripe\Webhook;
use Stripe\StripeClient;
use Everest\Models\Node;
use Everest\Models\User;
use Everest\Models\Server;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\DiscountCode;
use Everest\Exceptions\DisplayException;
use Everest\Models\Billing\BillingException;
use Everest\Services\Billing\CreateOrderService;
use Everest\Services\Billing\CreateServerService;
use Everest\Services\Billing\ServerRenewalService;
use Everest\Transformers\Api\Client\ServerTransformer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Everest\Transformers\Api\Client\DiscountCodeTransformer;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Http\Requests\Api\Client\Billing\CreateStripePaymentRequest;
use Everest\Http\Requests\Api\Client\Billing\ProcessStripePaymentRequest;
use Everest\Http\Requests\Api\Client\Billing\ValidateDiscountCodeRequest;

class StripeController extends ClientApiController
{
    public function __construct(
        private CreateOrderService $orderService,
        private ServerRenewalService $renewalService,
        private CreateServerService $deploymentService,
    ) {
        parent::__construct();

        $this->stripe = new StripeClient(config('modules.billing.keys.secret'));
    }

    /**
     * Create a new checkout session based on the selected information.
     */
    public function create(CreateStripePaymentRequest $request): string
    {
        $server = null;
        $product = Product::findOrFail($request->input('product_id'));
        $is_new_order = !$request->filled('server_id');
        $node_id = $is_new_order ? $request->input('node_id') : null;

        if (!$product->isPaid()) {
            throw new DisplayException('You cannot create a checkout session for a free product.');
        };

        if ($is_new_order) {
            if (!Node::findOrFail($request->input('node_id'))->deployable) {
                throw new DisplayException('Paid servers cannot be deployed to this node.');
            }
        } else {
            try {
                $server = $request->user()->servers()
                    ->where('id', $request->input('server_id'))
                    ->firstOrFail();
            } catch (ModelNotFoundException $exception) {
                throw new DisplayException('This server ID does not exist on your account.');
            };
        }

        // todo(jex): factor in discounts during sales process
        $price = $product->price;

        $transaction = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'customer_email' => $request->user()->email,

            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower(config('modules.billing.currency.code')),
                    'product_data' => [
                        'name' => $product->name,
                    ],
                    'unit_amount' => (int) round($price * 100),
                ],
                'quantity' => 1,
            ]],

            'success_url' => config('app.url') . '/account/billing/processing?session={CHECKOUT_SESSION_ID}',
            'cancel_url' => config('app.url') . '/account/billing/cancel',

            'metadata' => [
                'user_id' => (string) $request->user()->id,
                'customer_email' => $request->user()->email,
                'product_id' => (string) $product->id,
                'node_id' => (string) ($node_id ?? ''),
                'server_id' => (string) ($server?->id ?? 0),
                'variables' => json_encode($request->input('variables') ?? []),
                'order_type' => $is_new_order ? Order::TYPE_NEW : Order::TYPE_RENEWAL,
            ],
        ]);

        $this->orderService->create(
            $transaction->id,
            $request->user(),
            $product,
            Order::STATUS_PENDING,
            $is_new_order ? Order::TYPE_NEW : Order::TYPE_RENEWAL
        );

        return $transaction->url;
    }

    /**
     * Retrieve the checkout session from Stripe and process the order.
     */
    public function process(ProcessStripePaymentRequest $request): array
    {
        try {
            $transaction = $this->stripe->checkout->sessions->retrieve($request->input('session'));
        } catch (DisplayException $ex) {
            throw new DisplayException('Failed to process order: unable to retrieve session');
        }

            if ($transaction->payment_status !== 'paid') {
                throw new DisplayException('Payment not completed.');
            }

            $metadata = $transaction->metadata;

            $user = User::findOrFail($metadata->user_id);
            $product = Product::findOrFail($metadata->product_id);
            $order = Order::where('transaction_id', $transaction->id)->firstOrFail();

            if ($order->isProcessed()) {
                throw new DisplayException('This order has already been processed.');
            };

            try {
                if ($order->isRenewal()) {
                    $server = Server::findOrFail($metadata->server_id);

                    $new_server = $this->renewalService->handle($server);
                } else {
                    $new_server = $this->deploymentService->handle($user, $product, $metadata, $order);
                };

                $order->update(['status' => Order::STATUS_PROCESSED]);
            } catch (DisplayException $exception) {
                $order->update(['status' => Order::STATUS_FAILED]);

                BillingException::create([
                    'order_id' => $order->id,
                    'exception_type' => BillingException::TYPE_DEPLOYMENT,
                    'title' => 'Deployment or renewal of server failed',
                    'description' => $exception->getMessage(),
                ]);
            };

        return $this->fractal->item($new_server)
            ->transformWith(ServerTransformer::class)
            ->toArray();
    }

    /**
     * Validate the discount code submitted via the customer before checkout.
     */
    public function validateDiscount(ValidateDiscountCodeRequest $request): array
    {
        $discount_code = DiscountCode::where('code', $request->input('discount_code'))->first();

        if (!$discount_code || !$discount_code->isValid()) {
            throw new DisplayException('The discount code provided is not valid.');
        };

        return $this->fractal->item($discount_code)
            ->transformWith(DiscountCodeTransformer::class)
            ->toArray();
    }
}
