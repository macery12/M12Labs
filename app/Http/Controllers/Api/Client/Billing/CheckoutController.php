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
use Everest\Exceptions\Billing\BillingException as BillingExceptionClass;
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
            try {
                $this->stripe = new StripeClient($stripeSecret);
            } catch (\Exception $e) {
                \Log::error('Failed to initialize Stripe client', [
                    'error' => $e->getMessage(),
                ]);
            }
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

        // Get billing days (default to 30 if not provided)
        $billingDays = (int) ($request->input('billing_days') ?? 30);

        // Calculate price with coupon for new purchase
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId, 'new', $billingDays);

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
            $serverName,
            $billingDays
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

        // Get billing days (default to 30 if not provided)
        $billingDays = (int) ($request->input('billing_days') ?? 30);

        // Calculate price with coupon for renewal
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId, 'ren', $billingDays);

        // Validate this is a free renewal
        $this->validationService->validatePriceType($priceInfo['finalPrice'], true);

        // Lookup server scoped to the authenticated user
        $server = $user->servers()->findOrFail($serverId);

        // Process the renewal
        $result = $this->processorService->processRenewal($server, $product, $couponId, $billingDays);

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
     * @throws BillingExceptionClass if publishable key is missing or appears to be a secret key
     */
    public function getStripeKey(Request $request, int $id): JsonResponse
    {
        $publicKey = (string) config('modules.billing.keys.publishable') ?? null;

        if (!$publicKey) {
            throw new BillingExceptionClass(
                'The Stripe Public API key is missing',
                'Add the Stripe \'publishable\' key to your billing panel',
                BillingException::TYPE_STOREFRONT,
                null,
                'stripe',
                null,
                ['key_missing' => true]
            );
        }

        // SECURITY: Verify this is actually a publishable key, not a secret key
        // Publishable keys start with 'pk_', secret keys start with 'sk_'
        if (str_starts_with($publicKey, 'sk_')) {
            // Log this critical security issue
            \Log::critical('SECURITY: Secret key detected in publishable key field!', [
                'detected_type' => 'secret_key',
                'user_id' => $request->user()?->id,
            ]);
            
            throw new BillingExceptionClass(
                'Critical security error: Secret key in public field',
                'A secret key has been detected in the publishable key field. This is a severe security risk. Please reconfigure your Stripe keys immediately with the correct key types.',
                BillingException::TYPE_STOREFRONT,
                null,
                'stripe',
                null,
                ['security_violation' => true]
            );
        }

        // Verify it looks like a valid Stripe publishable key
        if (!str_starts_with($publicKey, 'pk_')) {
            throw new BillingExceptionClass(
                'Invalid Stripe publishable key format',
                'Publishable keys must start with \'pk_test_\' or \'pk_live_\'.',
                BillingException::TYPE_STOREFRONT,
                null,
                'stripe',
                null,
                ['invalid_format' => true]
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
     * @throws BillingExceptionClass
     */
    public function createIntent(Request $request, int $id): JsonResponse
    {
        $this->ensureStripeInitialized();
        
        $product = Product::findOrFail($id);

        try {
            // Get billing days (default to 30 if not provided)
            $billingDays = (int) ($request->input('billing_days') ?? 30);

            // Calculate price with coupon using validation service for new purchase
            $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
            $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId, 'new', $billingDays);

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
                throw new BillingExceptionClass(
                    'PaymentIntent client secret not generated',
                    'The payment intent was created but the client secret was not generated. Please check your Stripe configuration.',
                    BillingException::TYPE_PAYMENT,
                    null,
                    'stripe',
                    $paymentIntent->id ?? null,
                    ['product_id' => $product->id, 'amount' => $priceInfo['finalPrice']]
                );
            }

            return response()->json([
                'id' => $paymentIntent->id,
                'secret' => $paymentIntent->client_secret,
            ]);
        } catch (BillingExceptionClass $e) {
            throw $e;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Log::error('Stripe payment intent creation failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode(),
            ]);
            
            throw new BillingExceptionClass(
                'Stripe payment intent creation failed',
                'Failed to create payment intent: ' . $e->getMessage() . '. Please check your payment details and try again.',
                BillingException::TYPE_PAYMENT,
                null,
                'stripe',
                null,
                ['product_id' => $product->id, 'stripe_error' => $e->getStripeCode()],
                $e
            );
        } catch (\Exception $e) {
            \Log::error('Payment intent creation exception', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            
            throw new BillingExceptionClass(
                'Payment intent creation error',
                'An unexpected error occurred while creating payment intent: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                null,
                'stripe',
                null,
                ['product_id' => $product->id, 'error' => $e->getMessage()],
                $e
            );
        }
    }

    /**
     * Update a payment intent with order details.
     * 
     * @param Request $request
     * @param int|null $id Product ID
     * @return Response
     * @throws BillingExceptionClass
     */
    public function updateIntent(Request $request, ?int $id = null): Response
    {
        $this->ensureStripeInitialized();
        
        $product = Product::findOrFail($id);
        
        try {
            $intent = $this->stripe->paymentIntents->retrieve($request->input('intent'));

            // Validate billing is enabled
            $this->validationService->validateBillingEnabled();

            // Check if this is a renewal
            $isRenewal = $request->has('renewal') && $request->boolean('renewal');

            // For renewals, name and node_id are optional (server already exists)
            // For new purchases, they are required
            $serverName = trim((string) $request->input('name', ''));
            if (!$isRenewal && empty($serverName)) {
                throw new DisplayException('Server name is required.');
            }

            $nodeId = (int) $request->input('node_id');
            // Only validate node deployment for new purchases, not renewals
            if (!$isRenewal) {
                $this->validationService->validateNodeDeployment($nodeId, false);
            }

            if (!$intent) {
                throw new BillingExceptionClass(
                    'PaymentIntent does not exist',
                    'The payment intent requested does not exist. Please try creating a new payment.',
                    BillingException::TYPE_PAYMENT,
                    null,
                    'stripe',
                    $request->input('intent'),
                    ['intent_id' => $request->input('intent')]
                );
            }

            // For renewals, egg_id is not required
            $requestedEggId = $request->input('egg_id') ? (int) $request->input('egg_id') : null;
            $eggId = !$isRenewal ? $this->validationService->validateAndGetEggId($product, $requestedEggId) : null;

            // Get billing days (default to 30 if not provided)
            $billingDays = (int) ($request->input('billing_days') ?? 30);

            // Determine order type and calculate price with coupon
            $orderType = $this->getOrderType($request);
            $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
            $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId, $orderType, $billingDays);

            // Update the intent amount if it has changed
            if ($intent->amount !== (int)($priceInfo['finalPrice'] * 100)) {
                $intent->amount = (int)($priceInfo['finalPrice'] * 100);
            }

            $metadata = [
                'customer_email' => $request->user()->email,
                'customer_name' => $request->user()->username,
                'product_id' => (string) $id,
                'node_id' => $isRenewal ? '' : (string) $nodeId,
                'server_id' => (string) ($request->input('server_id') ?? 0),
                'coupon_id' => (string) ($couponId ?? ''),
                'egg_id' => $isRenewal ? '' : (string) $eggId,
                'billing_days' => (string) $billingDays,
                'name' => $isRenewal ? 'Server Renewal' : $serverName,
            ];

            $variables = $request->input('variables') ?? [];
            $metadata['variables'] = !empty($variables) ? json_encode($variables) : '';

            $intent->metadata = $metadata;
            $intent->save();

            // Create the order with coupon, egg, and billing days
            $this->orderService->create(
                $intent->id,
                $request->user(),
                $product,
                Order::STATUS_PENDING,
                $this->getOrderType($request),
                $couponId,
                $eggId,
                ['billing_days' => $billingDays]
            );

            return $this->returnNoContent();
        } catch (BillingExceptionClass $e) {
            throw $e;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Log::error('Stripe intent update failed', [
                'intent_id' => $request->input('intent'),
                'error' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode(),
            ]);
            
            throw new BillingExceptionClass(
                'Stripe payment intent update failed',
                'Failed to update payment intent: ' . $e->getMessage() . '. Please try again.',
                BillingException::TYPE_PAYMENT,
                null,
                'stripe',
                $request->input('intent'),
                ['stripe_error' => $e->getStripeCode()],
                $e
            );
        } catch (\Exception $e) {
            \Log::error('Payment intent update exception', [
                'intent_id' => $request->input('intent'),
                'error' => $e->getMessage(),
            ]);
            
            throw new BillingExceptionClass(
                'Payment intent update error',
                'An unexpected error occurred while updating payment intent: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                null,
                'stripe',
                $request->input('intent'),
                ['error' => $e->getMessage()],
                $e
            );
        }
    }

    /**
     * Process a paid order.
     * 
     * @param Request $request
     * @return Response
     * @throws BillingExceptionClass
     */
    public function processPaid(Request $request): Response
    {
        $this->ensureStripeInitialized();
        
        try {
            $order = Order::where('user_id', $request->user()->id)->latest()->first();
            $intent = $this->stripe->paymentIntents->retrieve($request->input('intent'));

            // Validate billing is enabled
            $this->validationService->validateBillingEnabled();

            if (!$intent) {
                throw new BillingExceptionClass(
                    'Unable to fetch PaymentIntent',
                    'Unable to fetch payment intent from Stripe. Please try again or contact support.',
                    BillingException::TYPE_PAYMENT,
                    $order?->id,
                    'stripe',
                    $request->input('intent'),
                    ['intent_id' => $request->input('intent')]
                );
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
                throw new BillingExceptionClass(
                    'Payment not ready for capture',
                    'The payment was not successful or is not ready to be captured. Status: ' . $intent->status,
                    BillingException::TYPE_PAYMENT,
                    $order->id,
                    'stripe',
                    $intent->id,
                    ['intent_status' => $intent->status]
                );
            }

            // Use centralized fulfillment service to create/renew server
            $server = $this->fulfillmentService->fulfillOrder($request, $order, $intent->metadata);

            // Capture the payment after processing the order
            if ($intent->status === 'requires_capture') {
                try {
                    $intent->capture();
                } catch (\Stripe\Exception\ApiErrorException $ex) {
                    // Payment capture failed - delete the server and log exception
                    $server->delete();

                    throw new BillingExceptionClass(
                        'Failed to capture payment via Stripe',
                        'The server was created but payment capture failed: ' . $ex->getMessage() . '. The server has been removed. Please try again.',
                        BillingException::TYPE_PAYMENT,
                        $order->id,
                        'stripe',
                        $intent->id,
                        ['stripe_error' => $ex->getStripeCode(), 'server_id' => $server->id],
                        $ex
                    );
                } catch (\Exception $ex) {
                    // Unexpected error during capture - delete the server
                    $server->delete();

                    throw new BillingExceptionClass(
                        'Unexpected error during payment capture',
                        'An unexpected error occurred while capturing payment: ' . $ex->getMessage() . '. The server has been removed. Please try again.',
                        BillingException::TYPE_PAYMENT,
                        $order->id,
                        'stripe',
                        $intent->id,
                        ['server_id' => $server->id, 'error' => $ex->getMessage()],
                        $ex
                    );
                }
            }

            return $this->returnNoContent();
        } catch (BillingExceptionClass $e) {
            throw $e;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Log::error('Stripe order processing failed', [
                'intent_id' => $request->input('intent'),
                'error' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode(),
            ]);
            
            $order = Order::where('user_id', $request->user()->id)->latest()->first();
            throw new BillingExceptionClass(
                'Stripe order processing failed',
                'Failed to process order: ' . $e->getMessage() . '. Please contact support.',
                BillingException::TYPE_PAYMENT,
                $order?->id,
                'stripe',
                $request->input('intent'),
                ['stripe_error' => $e->getStripeCode()],
                $e
            );
        } catch (\Exception $e) {
            \Log::error('Order processing exception', [
                'intent_id' => $request->input('intent'),
                'error' => $e->getMessage(),
            ]);
            
            $order = Order::where('user_id', $request->user()->id)->latest()->first();
            throw new BillingExceptionClass(
                'Order processing error',
                'An unexpected error occurred while processing your order: ' . $e->getMessage(),
                BillingException::TYPE_PAYMENT,
                $order?->id,
                'stripe',
                $request->input('intent'),
                ['error' => $e->getMessage()],
                $e
            );
        }
    }

    /**
     * Ensure Stripe client is initialized.
     * 
     * @throws BillingExceptionClass if Stripe is not configured
     */
    private function ensureStripeInitialized(): void
    {
        if (!$this->stripe) {
            throw new BillingExceptionClass(
                'Stripe is not configured',
                'Stripe payment processing is not configured. Please contact support or try a different payment method.',
                BillingException::TYPE_STOREFRONT,
                null,
                'stripe',
                null,
                ['configured' => false]
            );
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
