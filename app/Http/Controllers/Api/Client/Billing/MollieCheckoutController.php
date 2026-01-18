<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Everest\Models\Server;
use Everest\Models\Billing\Order;
use Illuminate\Http\JsonResponse;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Models\Billing\CouponUsage;
use Everest\Services\Billing\CreateOrderService;
use Everest\Services\Billing\CreateServerService;
use Everest\Services\Billing\OrderProcessorService;
use Everest\Services\Billing\MolliePaymentService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Http\Controllers\Api\Client\ClientApiController;

class MollieCheckoutController extends ClientApiController
{
    public function __construct(
        private MolliePaymentService $mollieService,
        private BillingValidationService $validationService,
        private OrderProcessorService $processorService,
        private CreateOrderService $orderService,
        private CreateServerService $serverCreation,
    ) {
        parent::__construct();
    }

    /**
     * Create a Mollie payment.
     *
     * @param Request $request
     * @param int $id Product ID
     * @return JsonResponse
     */
    public function createPayment(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        // Calculate price with coupon using validation service for new purchase
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId, 'new');

        // Validate this is not a free order
        $this->validationService->validatePriceType($priceInfo['finalPrice'], false);

        // Generate a secure random token
        $token = \Illuminate\Support\Str::uuid()->toString();

        // Use the return_url from frontend request and append token
        $baseReturnUrl = $request->input('return_url', url('/account/billing/processing'));
        $returnUrl = str_contains($baseReturnUrl, '?')
            ? $baseReturnUrl . '&token=' . $token
            : $baseReturnUrl . '?token=' . $token;
        
        $payment = $this->mollieService->createPayment(
            $product,
            $priceInfo['finalPrice'],
            $couponId,
            $returnUrl
        );

        // Store the token mapping in an order record (pending state, will be updated later)
        // Use null for intent since Mollie doesn't use payment intents
        $this->orderService->create(
            null, // Mollie doesn't use payment_intent_id
            $request->user(),
            $product,
            Order::STATUS_PENDING,
            Order::TYPE_NEW,
            $couponId,
            null, // egg_id will be set in updatePayment
            [
                'payment_processor' => 'mollie',
                'mollie_payment_id' => $payment->id,
                'payment_token' => $token,
                'name' => 'Pending',
                'node_id' => null,
                'server_id' => null,
                'variables' => [],
            ]
        );

        // Return payment info
        return response()->json([
            'id' => $payment->id,
            'token' => $token,
            'checkout_url' => $payment->getCheckoutUrl(),
        ]);
    }

    /**
     * Update a payment with order details.
     *
     * @param Request $request
     * @param int $id Product ID
     * @return Response
     */
    public function updatePayment(Request $request, int $id): Response
    {
        $product = Product::findOrFail($id);
        $paymentId = $request->input('payment_id');

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

        // Validate and get egg ID
        $requestedEggId = $request->input('egg_id') ? (int) $request->input('egg_id') : null;
        $eggId = $this->validationService->validateAndGetEggId($product, $requestedEggId);

        // Determine order type and calculate price with coupon
        $orderType = $this->getOrderType($request);
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $variables = $request->input('variables', []);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;

        // Find the existing pending order and update it
        $order = Order::where('mollie_payment_id', $paymentId)
            ->where('user_id', $request->user()->id)
            ->where('status', Order::STATUS_PENDING)
            ->firstOrFail();

        $order->update([
            'name' => $serverName,
            'node_id' => $nodeId,
            'server_id' => $serverId,
            'egg_id' => $eggId,
            'type' => $orderType,
            'coupon_id' => $couponId,
            'variables' => $variables,
        ]);

        return $this->returnNoContent();
    }

    /**
     * Process a Mollie payment (webhook handler).
     * 
     * Following Mollie best practices:
     * - Webhook receives only payment ID for security
     * - Fetch full payment details from Mollie API
     * - Handle all payment statuses: paid, failed, expired, canceled, authorized, pending, open
     * - Idempotent processing to prevent duplicate order fulfillment
     *
     * @param Request $request
     * @return Response
     */
    public function processPayment(Request $request): Response
    {
        $paymentId = $request->input('id');

        if (!$paymentId) {
            // Return 200 to prevent Mollie retries, but log the issue
            \Log::warning('Mollie webhook called without payment ID');
            return $this->returnNoContent();
        }

        // Find the order by mollie_payment_id
        $order = Order::where('mollie_payment_id', $paymentId)->latest()->first();

        if (!$order) {
            // Return 200 to prevent Mollie retries for non-existent orders
            \Log::warning("Mollie webhook: Order not found for payment ID: {$paymentId}");
            return $this->returnNoContent();
        }

        // IDEMPOTENCY: Check if payment is already in a final state (processed or failed)
        // This prevents duplicate processing if webhook is called multiple times
        if (in_array($order->status, [Order::STATUS_PROCESSED, Order::STATUS_FAILED], true)) {
            \Log::info("Mollie webhook: Order {$order->id} already in final state: {$order->status}");
            return $this->returnNoContent();
        }

        try {
            // Validate billing is enabled
            $this->validationService->validateBillingEnabled();

            // SECURITY: Fetch payment details from Mollie API (never trust webhook data directly)
            $payment = $this->mollieService->getPayment($paymentId);

            // Handle different payment statuses according to Mollie documentation
            if ($payment->isPaid()) {
                // PAID: Payment successful - fulfill the order
                $this->fulfillOrder($request, $order, $payment);
            } elseif ($payment->isFailed()) {
                // FAILED: Payment attempt failed definitively
                \Log::info("Mollie payment {$paymentId} failed for order {$order->id}");
                $order->update(['status' => Order::STATUS_FAILED]);
            } elseif ($payment->isExpired()) {
                // EXPIRED: Payment window expired (customer didn't complete in time)
                \Log::info("Mollie payment {$paymentId} expired for order {$order->id}");
                $order->update(['status' => Order::STATUS_FAILED]);
            } elseif ($payment->isCanceled()) {
                // CANCELED: Customer actively canceled the payment
                \Log::info("Mollie payment {$paymentId} canceled by customer for order {$order->id}");
                $order->update(['status' => Order::STATUS_FAILED]);
            } elseif ($payment->isAuthorized()) {
                // AUTHORIZED: Payment authorized but not captured yet (Klarna, credit cards)
                // Keep as pending until captured
                \Log::info("Mollie payment {$paymentId} authorized for order {$order->id}");
                $order->update(['status' => Order::STATUS_PENDING]);
            } elseif ($payment->isPending() || $payment->isOpen()) {
                // PENDING/OPEN: Payment in progress or just created - no action needed yet
                \Log::info("Mollie payment {$paymentId} is pending/open for order {$order->id}");
                // Keep current status
            } else {
                // Unknown status - log for investigation
                \Log::warning("Mollie payment {$paymentId} has unknown status: {$payment->status}");
            }
        } catch (\Exception $e) {
            // Log error but return 200 to prevent infinite Mollie retries
            \Log::error("Mollie webhook error for payment {$paymentId}: " . $e->getMessage());
        }

        return $this->returnNoContent();
    }

    /**
     * Fulfill an order after successful payment.
     * 
     * @param Request $request
     * @param Order $order
     * @param \Mollie\Api\Resources\Payment $payment
     * @return void
     */
    private function fulfillOrder(Request $request, Order $order, $payment): void
    {
        // Double-check idempotency before fulfillment
        if ($order->status === Order::STATUS_PROCESSED) {
            \Log::info("Order {$order->id} already processed, skipping fulfillment");
            return;
        }

        $metadata = $payment->metadata;
        $product = Product::findOrFail($metadata->product_id);

        \Log::info("Fulfilling order {$order->id} for payment {$payment->id}");

        // Process the renewal or product purchase
        if ($order->type === Order::TYPE_REN) {
            // For renewals, get the server from the stored server_id
            if (!$order->server_id) {
                throw new DisplayException('Server ID not found in order record for renewal.');
            }
            
            $server = Server::findOrFail($order->server_id);

            // Use the unified processor service for renewal
            $this->processorService->processRenewal($server, $product, $order->coupon_id);
            
            \Log::info("Completed server renewal for order {$order->id}, server {$server->id}");
        } else {
            // For new purchases, create the server using stored order data
            $user = \Everest\Models\User::findOrFail($order->user_id);
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

            $orderMetadata = (object) [
                'product_id' => $metadata->product_id,
                'node_id' => $order->node_id,
                'egg_id' => $order->egg_id,
                'name' => $order->name,
                'variables' => $order->variables ?? [],
            ];

            $server = $this->serverCreation->process($request, $product, $orderMetadata, $order);
            
            \Log::info("Created new server {$server->id} for order {$order->id}");
        }

        // Record coupon usage for non-renewal orders (idempotent - unique constraint prevents duplicates)
        if ($order->type !== Order::TYPE_REN && $order->coupon_id) {
            // Check if coupon usage already exists to prevent duplicates
            $existingUsage = CouponUsage::where('order_id', $order->id)->first();
            if (!$existingUsage) {
                CouponUsage::create([
                    'coupon_id' => $order->coupon_id,
                    'user_id' => $order->user_id,
                    'order_id' => $order->id,
                    'used_at' => now(),
                ]);
            }
        }

        // Mark the order as processed (final state - idempotency checkpoint)
        if ($order->type !== Order::TYPE_REN) {
            $order->update(['status' => Order::STATUS_PROCESSED]);
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

    /**
     * Check the status of a specific Mollie payment by payment ID.
     * 
     * Returns detailed status information including:
     * - processed: Order is completed
     * - failed: Order failed (payment failed, expired, or canceled)
     * - pending: Order is still pending (payment open, pending, or authorized)
     * - payment_id: Mollie payment ID
     * - payment_status: Current Mollie payment status (paid, failed, expired, canceled, authorized, pending, open)
     *
     * This endpoint also acts as a fallback processor - if the webhook hasn't run yet but
     * the payment is actually paid, it will process the order.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkPaymentStatus(Request $request): JsonResponse
    {
        $paymentId = $request->input('payment_id');
        
        if (!$paymentId) {
            // Fallback: Get the latest order for this user if no payment_id provided
            $order = Order::where('user_id', $request->user()->id)
                ->where('payment_processor', 'mollie')
                ->latest()
                ->first();
        } else {
            // Get the order by mollie_payment_id
            $order = Order::where('mollie_payment_id', $paymentId)
                ->where('user_id', $request->user()->id)
                ->first();
        }

        if (!$order) {
            return response()->json([
                'processed' => false,
                'failed' => false,
                'pending' => true,
                'payment_id' => $paymentId,
                'payment_status' => 'unknown',
            ]);
        }

        // Get current payment status from Mollie for accurate real-time status
        $paymentStatus = 'unknown';
        $payment = null;
        try {
            if ($order->mollie_payment_id) {
                $payment = $this->mollieService->getPayment($order->mollie_payment_id);
                $paymentStatus = $this->mollieService->getPaymentStatus($order->mollie_payment_id);
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to fetch Mollie payment status for {$order->mollie_payment_id}: " . $e->getMessage());
        }

        // FALLBACK PROCESSING: If payment is paid but order is still pending, process it now
        // This handles cases where webhook hasn't run yet or failed
        if ($payment && $payment->isPaid() && $order->status === Order::STATUS_PENDING) {
            try {
                \Log::info("Status check triggering fallback fulfillment for order {$order->id}");
                $this->fulfillOrder($request, $order, $payment);
                // Reload order to get updated status
                $order->refresh();
            } catch (\Exception $e) {
                \Log::error("Fallback fulfillment failed for order {$order->id}: " . $e->getMessage());
            }
        }
        
        // If payment failed/expired/canceled, update order status
        if ($payment && $order->status === Order::STATUS_PENDING) {
            if ($payment->isFailed() || $payment->isExpired() || $payment->isCanceled()) {
                $order->update(['status' => Order::STATUS_FAILED]);
                $order->refresh();
            }
        }

        return response()->json([
            'processed' => $order->status === Order::STATUS_PROCESSED,
            'failed' => $order->status === Order::STATUS_FAILED,
            'pending' => $order->status === Order::STATUS_PENDING,
            'payment_id' => $order->mollie_payment_id,
            'payment_status' => $paymentStatus,
        ]);
    }

    /**
     * Get payment ID from token.
     *
     * @param string $token
     * @return JsonResponse
     */
    public function getPaymentFromToken(string $token): JsonResponse
    {
        $order = Order::where('payment_token', $token)
            ->where('payment_processor', 'mollie')
            ->first();

        if (!$order) {
            return response()->json([
                'error' => 'Invalid or expired token',
            ], 404);
        }

        return response()->json([
            'payment_id' => $order->mollie_payment_id,
            'user_id' => $order->user_id,
        ]);
    }
}
