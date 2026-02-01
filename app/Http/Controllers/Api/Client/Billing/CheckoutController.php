<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Stripe\StripeClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Everest\Models\Billing\Order;
use Illuminate\Http\JsonResponse;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Models\Billing\BillingException;
use Everest\Services\Billing\CreateOrderService;
use Everest\Services\Billing\CreateServerService;
use Everest\Services\Billing\OrderProcessorService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Services\Billing\ServerFulfillmentService;
use Everest\Transformers\Api\Client\ServerTransformer;
use Everest\Http\Controllers\Api\Client\ClientApiController;

/**
 * Unified Checkout Controller
 * 
 * Handles both free and paid product purchases and renewals.
 * This controller consolidates billing operations that were previously split
 * between FreeProductController and PaymentController.
 */
class CheckoutController extends ClientApiController
{
    private ?StripeClient $stripe = null;

    public function __construct(
        private BillingValidationService $validationService,
        private OrderProcessorService $processorService,
        private CreateOrderService $orderService,
        private CreateServerService $serverCreation,
        private ServerFulfillmentService $fulfillmentService,
    ) {
        parent::__construct();

        // Initialize Stripe client if secret key is configured
        $stripeSecret = config('modules.billing.keys.secret');
        if ($stripeSecret) {
            $this->stripe = new StripeClient($stripeSecret);
        }
    }

    /**
     * Process a free product purchase.
     * 
     * @param Request $request
     * @return array
     */
    public function processFree(Request $request): array
    {
        $user = $request->user();
        $product = Product::findOrFail($request->input('product'));

        // Validate billing is enabled
        $this->validationService->validateBillingEnabled();

        // Get and validate server name
        $serverName = trim((string) $request->input('name', ''));
        if (empty($serverName)) {
            throw new DisplayException('Server name is required.');
        }

        // Calculate price with coupon for new purchase
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId, 'new');

        // Validate this is a free order
        $this->validationService->validatePriceType($priceInfo['finalPrice'], true);

        // Validate user doesn't already own this free product
        $this->validationService->validateFreeProductOwnership($user->id, $product);

        // Validate node deployment
        $nodeId = (int) $request->input('node');
        $this->validationService->validateNodeDeployment($nodeId, true);

        // Validate and get egg ID
        $requestedEggId = $request->input('egg_id') ? (int) $request->input('egg_id') : null;
        $eggId = $this->validationService->validateAndGetEggId($product, $requestedEggId);

        // Process the order
        $variables = $request->input('variables', []);
        $result = $this->processorService->createServerOrder(
            $request,
            $user,
            $product,
            $nodeId,
            $eggId,
            $couponId,
            $variables,
            null, // No payment intent ID for free orders
            $serverName
        );

        return $this->fractal->item($result['server'])
            ->transformWith(ServerTransformer::class)
            ->toArray();
    }

    /**
     * Renew a free server.
     * 
     * @param Request $request
     * @return array
     */
    public function renewFree(Request $request): array
    {
        $user = $request->user();
        $serverId = (int) $request->input('server_id');
        $product = Product::findOrFail($request->input('product'));

        // Validate billing is enabled
        $this->validationService->validateBillingEnabled();

        // Calculate price with coupon for renewal
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId, 'ren');

        // Validate this is a free renewal
        $this->validationService->validatePriceType($priceInfo['finalPrice'], true);

        // Lookup server scoped to the authenticated user
        $server = $user->servers()->findOrFail($serverId);

        // Process the renewal
        $result = $this->processorService->processRenewal($server, $product, $couponId);

        return $this->fractal->item($result['server'])
            ->transformWith(ServerTransformer::class)
            ->toArray();
    }

    /**
     * Get Stripe public key.
     * 
     * This endpoint is safe to call from the frontend as it only returns
     * the publishable key, which is meant to be public.
     * 
     * @param Request $request
     * @param int $id Product ID
     * @return JsonResponse
     * @throws DisplayException if publishable key is missing or appears to be a secret key
     */
    public function getStripeKey(Request $request, int $id): JsonResponse
    {
        $publicKey = (string) config('modules.billing.keys.publishable') ?? null;

        if (!$publicKey) {
            BillingException::create([
                'exception_type' => BillingException::TYPE_STOREFRONT,
                'title' => 'The Stripe Public API key is missing',
                'description' => 'Add the Stripe \'publishable\' key to your billing panel',
            ]);
        }

        // SECURITY: Verify this is actually a publishable key, not a secret key
        // Publishable keys start with 'pk_', secret keys start with 'sk_'
        if (str_starts_with($publicKey, 'sk_')) {
            // Log this critical security issue
            \Log::critical('SECURITY: Secret key detected in publishable key field!', [
                'detected_type' => 'secret_key',
                'user_id' => $request->user()?->id,
            ]);
            
            throw new DisplayException(
                'Critical configuration error: A secret key has been detected in the publishable key field. ' .
                'This is a severe security risk. Please reconfigure your Stripe keys immediately with the correct key types.'
            );
        }

        // Verify it looks like a valid Stripe publishable key
        if (!str_starts_with($publicKey, 'pk_')) {
            throw new DisplayException(
                'Invalid Stripe publishable key format. Publishable keys must start with \'pk_test_\' or \'pk_live_\'.'
            );
        }

        return response()->json(['key' => $publicKey]);
    }

    /**
     * Create a Stripe payment intent.
     * 
     * @param Request $request
     * @param int $id Product ID
     * @return JsonResponse
     */
    public function createIntent(Request $request, int $id): JsonResponse
    {
        $this->ensureStripeInitialized();
        
        $product = Product::findOrFail($id);

        // Calculate price with coupon using validation service for new purchase
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId, 'new');

        // Validate this is not a free order
        $this->validationService->validatePriceType($priceInfo['finalPrice'], false);

        $paymentMethodTypes = ['card'];

        if (config('modules.billing.paypal')) {
            $paymentMethodTypes[] = 'paypal';
        }

        if (config('modules.billing.link')) {
            $paymentMethodTypes[] = 'link';
        }

        $paymentIntent = $this->stripe->paymentIntents->create([
            'amount' => $priceInfo['finalPrice'] * 100,
            'currency' => strtolower(config('modules.billing.currency.code')),
            'payment_method_types' => array_values($paymentMethodTypes),
            'capture_method' => 'manual',
        ]);

        if (!$paymentIntent->client_secret) {
            BillingException::create([
                'exception_type' => BillingException::TYPE_STOREFRONT,
                'title' => 'The PaymentIntent client secret was not generated',
                'description' => 'Double check your billing API keys and Stripe Dashboard',
            ]);
        }

        return response()->json([
            'id' => $paymentIntent->id,
            'secret' => $paymentIntent->client_secret,
        ]);
    }

    /**
     * Update a payment intent with order details.
     * 
     * @param Request $request
     * @param int|null $id Product ID
     * @return Response
     */
    public function updateIntent(Request $request, ?int $id = null): Response
    {
        $this->ensureStripeInitialized();
        
        $product = Product::findOrFail($id);
        $intent = $this->stripe->paymentIntents->retrieve($request->input('intent'));

        // Validate billing is enabled
        $this->validationService->validateBillingEnabled();

        // Get and validate server name
        $serverName = trim((string) $request->input('name', ''));
        if (empty($serverName)) {
            throw new DisplayException('Server name is required.');
        }

        // Validate node deployment for paid products
        $nodeId = (int) $request->input('node_id');
        $this->validationService->validateNodeDeployment($nodeId, false);

        if (!$intent) {
            BillingException::create([
                'exception_type' => BillingException::TYPE_STOREFRONT,
                'title' => 'The PaymentIntent requested does not exist',
                'description' => 'Check Stripe Dashboard and ask in the Jexactyl Discord for support',
            ]);
        }

        // Validate and get egg ID
        $requestedEggId = $request->input('egg_id') ? (int) $request->input('egg_id') : null;
        $eggId = $this->validationService->validateAndGetEggId($product, $requestedEggId);

        // Determine order type and calculate price with coupon
        $orderType = $this->getOrderType($request);
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId, $orderType);

        // Update the intent amount if it has changed
        if ($intent->amount !== (int)($priceInfo['finalPrice'] * 100)) {
            $intent->amount = (int)($priceInfo['finalPrice'] * 100);
        }

        $metadata = [
            'customer_email' => $request->user()->email,
            'customer_name' => $request->user()->username,
            'product_id' => (string) $id,
            'node_id' => (string) $nodeId,
            'server_id' => (string) ($request->input('server_id') ?? 0),
            'coupon_id' => (string) ($couponId ?? ''),
            'egg_id' => (string) $eggId,
            'name' => $serverName,
        ];

        $variables = $request->input('variables') ?? [];
        $metadata['variables'] = !empty($variables) ? json_encode($variables) : '';

        $intent->metadata = $metadata;
        $intent->save();

        // Create the order with coupon and egg
        $this->orderService->create(
            $intent->id,
            $request->user(),
            $product,
            Order::STATUS_PENDING,
            $this->getOrderType($request),
            $couponId,
            $eggId,
        );

        return $this->returnNoContent();
    }

    /**
     * Process a paid order.
     * 
     * @param Request $request
     * @return Response
     */
    public function processPaid(Request $request): Response
    {
        $this->ensureStripeInitialized();
        
        $order = Order::where('user_id', $request->user()->id)->latest()->first();
        $intent = $this->stripe->paymentIntents->retrieve($request->input('intent'));

        // Validate billing is enabled
        $this->validationService->validateBillingEnabled();

        if (!$intent) {
            BillingException::create([
                'order_id' => $order->id,
                'exception_type' => BillingException::TYPE_DEPLOYMENT,
                'title' => 'Unable to fetch PaymentIntent while processing order',
                'description' => 'Check Stripe Dashboard and ask in the Jexactyl Discord for support',
            ]);
            throw new DisplayException('Unable to fetch payment intent from Stripe.');
        }

        // Check if order has already been processed
        if (
            $order->status === Order::STATUS_PROCESSED
            && $intent->id === $order->payment_intent_id
        ) {
            throw new DisplayException('This order has already been processed.');
        }

        // If the payment wasn't successful, mark the order as failed
        if ($intent->status !== 'requires_capture') {
            $order->update(['status' => Order::STATUS_FAILED]);
            throw new DisplayException('The order has been canceled.');
        }

        // Use centralized fulfillment service to create/renew server
        $server = $this->fulfillmentService->fulfillOrder($request, $order, $intent->metadata);

        // Capture the payment after processing the order
        if ($intent->status === 'requires_capture') {
            try {
                $intent->capture();
            } catch (DisplayException $ex) {
                $server->delete();

                BillingException::create([
                    'order_id' => $order->id,
                    'exception_type' => BillingException::TYPE_PAYMENT,
                    'title' => 'Failed to capture payment via Stripe',
                    'description' => 'Check Stripe Dashboard and ask in the Jexactyl Discord for support',
                ]);

                throw new DisplayException('Unable to capture payment for this order.');
            }
        }

        return $this->returnNoContent();
    }

    /**
     * Ensure Stripe client is initialized.
     * 
     * @throws DisplayException if Stripe is not configured
     */
    private function ensureStripeInitialized(): void
    {
        if (!$this->stripe) {
            throw new DisplayException('Stripe is not configured. Please add your Stripe API keys.');
        }
    }

    /**
     * Determine the order type (NEW, UPGRADE, or RENEWAL).
     * 
     * @param Request $request
     * @return string
     */
    private function getOrderType(Request $request): string
    {
        if ($request->has('renewal') && $request->boolean('renewal')) {
            return Order::TYPE_REN;
        }

        return Order::TYPE_NEW;
    }
}
