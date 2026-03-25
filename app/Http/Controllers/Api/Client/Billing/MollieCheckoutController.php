<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Everest\Models\Billing\Order;
use Illuminate\Http\JsonResponse;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Models\Billing\BillingException;
use Everest\Services\Security\LogSanitizer;
use Everest\Services\Billing\CreateOrderService;
use Everest\Services\Billing\CreateServerService;
use Everest\Services\Billing\MolliePaymentService;
use Everest\Services\Billing\OrderProcessorService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Services\Billing\ServerFulfillmentService;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Exceptions\Billing\BillingException as BillingExceptionClass;
use Everest\Traits\ValidatesRedirectUrl;

class MollieCheckoutController extends ClientApiController
{
    use ValidatesRedirectUrl;

    public function __construct(
        private MolliePaymentService $mollieService,
        private BillingValidationService $validationService,
        private OrderProcessorService $processorService,
        private CreateOrderService $orderService,
        private CreateServerService $serverCreation,
        private ServerFulfillmentService $fulfillmentService,
    ) {
        parent::__construct();
    }

    /**
     * Create a Mollie payment.
     *
     * @param int $id Product ID
     */
    public function createPayment(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        // Check if this is a renewal payment
        $isRenewal = $request->boolean('renewal', false);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;
        $billingDays = (int) ($request->input('billing_days') ?? 30);

        // Determine order type and calculate price
        $orderType = $isRenewal ? Order::TYPE_REN : Order::TYPE_NEW;
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon(
            $product,
            $couponId,
            $isRenewal ? 'ren' : 'new',
            $billingDays,
            null, // node ID
            $request->user()->id
        );

        // Validate this is not a free order
        $this->validationService->validatePriceType($priceInfo['finalPrice'], false);

        // Generate a secure random token
        $token = \Illuminate\Support\Str::uuid()->toString();

        // Use the return_url from frontend request and append token
        $baseReturnUrl = $request->input('return_url', url('/account/billing/processing'));
        $returnUrl = str_contains($baseReturnUrl, '?')
            ? $baseReturnUrl . '&token=' . $token . '&processor=mollie'
            : $baseReturnUrl . '?token=' . $token . '&processor=mollie';

        $payment = $this->mollieService->createPayment(
            $product,
            $priceInfo['finalPrice'],
            $couponId,
            $returnUrl,
            $token
        );
        $rawCheckoutUrl = $payment->getCheckoutUrl();
        if (!$rawCheckoutUrl) {
            throw new DisplayException('Mollie checkout URL unavailable.');
        }
        $checkoutUrl = $this->validateRedirectUrl($rawCheckoutUrl, ['mollie.com']);

        // For renewals, we have all the information upfront
        // For new orders, some fields will be set later via updatePayment
        $orderData = [
            'payment_processor' => 'mollie',
            'mollie_payment_id' => $payment->id,
            'payment_token' => $token,
            'name' => $isRenewal ? 'Server Renewal' : 'Pending',
            'node_id' => null,
            'server_id' => $isRenewal ? $serverId : null,
            'billing_days' => $billingDays,
            'variables' => [],
        ];

        // Store the token mapping in an order record (pending state)
        // Use null for intent since Mollie doesn't use payment intents
        $this->orderService->create(
            null, // Mollie doesn't use payment_intent_id
            $request->user(),
            $product,
            Order::STATUS_PENDING,
            $orderType,
            $couponId,
            null, // egg_id will be set in updatePayment for new orders
            $orderData
        );

        // Return payment info
        return response()->json([
            'id' => $payment->id,
            'token' => $token,
            'checkout_url' => $checkoutUrl,
        ]);
    }

    /**
     * Safely redirect the user to Mollie's hosted checkout after validating the URL.
     */
    public function redirectToCheckout(Request $request, string $paymentId): RedirectResponse
    {
        // Authorization guard: ensure the payment belongs to the current user before redirecting.
        $order = Order::where('mollie_payment_id', $paymentId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $checkoutUrl = $this->mollieService->getCheckoutUrl($paymentId);
        if (!$checkoutUrl) {
            throw new DisplayException('Mollie checkout URL unavailable.');
        }
        $safeUrl = $this->validateRedirectUrl($checkoutUrl, ['mollie.com']);

        return redirect()->away($safeUrl);
    }

    /**
     * Update a payment with order details.
     *
     * @param int $id Product ID
     */
    public function updatePayment(Request $request, int $id): Response
    {
        $product = Product::findOrFail($id);
        $paymentId = $request->input('payment_id');

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
            $this->validationService->validateNodeSelectionForProduct($nodeId, $product);
            $this->validationService->validateNodeDeployment($nodeId, false);
        }

        // For renewals, egg_id is not required
        $requestedEggId = $request->input('egg_id') ? (int) $request->input('egg_id') : null;
        $eggId = $isRenewal ? null : $this->validationService->validateAndGetEggId($product, $requestedEggId);
        $billingDays = (int) ($request->input('billing_days') ?? 30);

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
            'name' => $isRenewal ? 'Server Renewal' : $serverName,
            'node_id' => $isRenewal ? null : $nodeId,
            'server_id' => $serverId,
            'egg_id' => $isRenewal ? null : $eggId,
            'type' => $orderType,
            'coupon_id' => $couponId,
            'billing_days' => $billingDays,
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
            \Log::warning('Mollie webhook order not found', [
                'payment_id' => LogSanitizer::maskIdentifier($paymentId),
            ]);

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
                \Log::info('Mollie webhook marked payment failed', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'order_id' => $order->id,
                ]);
                $order->update(['status' => Order::STATUS_FAILED]);
                $this->dispatchPaymentFailedEmail($order, 'Payment failed', 'mollie');
            } elseif ($payment->isExpired()) {
                // EXPIRED: Payment window expired (customer didn't complete in time)
                \Log::info('Mollie webhook marked payment expired', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'order_id' => $order->id,
                ]);
                $order->update(['status' => Order::STATUS_FAILED]);
                $this->dispatchPaymentFailedEmail($order, 'Payment expired - customer did not complete payment in time', 'mollie');
            } elseif ($payment->isCanceled()) {
                // CANCELED: Customer actively canceled the payment
                \Log::info('Mollie webhook marked payment canceled', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'order_id' => $order->id,
                ]);
                $order->update(['status' => Order::STATUS_FAILED]);
                $this->dispatchPaymentFailedEmail($order, 'Payment canceled by customer', 'mollie');
            } elseif ($payment->isAuthorized()) {
                // AUTHORIZED: Payment authorized but not captured yet (Klarna, credit cards)
                // Keep as pending until captured
                \Log::info('Mollie webhook payment authorized', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'order_id' => $order->id,
                ]);
                $order->update(['status' => Order::STATUS_PENDING]);
            } elseif ($payment->isPending() || $payment->isOpen()) {
                // PENDING/OPEN: Payment in progress or just created - no action needed yet
                \Log::info('Mollie webhook payment still pending', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'order_id' => $order->id,
                    'payment_status' => $payment->status,
                ]);
            // Keep current status
            } else {
                // Unknown status - log for investigation
                \Log::warning('Mollie webhook returned unknown status', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'payment_status' => $payment->status,
                ]);
            }
        } catch (BillingExceptionClass $e) {
            // Log the billing exception but return 200 to prevent Mollie retries
            // The exception is already logged to the database by BillingException
            \Log::error('Mollie webhook billing exception', [
                'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                'exception_type' => $e->getExceptionType(),
                'message' => $e->getMessage(),
                'order_id' => $e->getOrderId(),
            ]);
        } catch (\Exception $e) {
            // Log error but return 200 to prevent infinite Mollie retries
            // Create a billing exception for admin review
            \Log::error('Mollie webhook error', array_merge([
                'payment_id' => LogSanitizer::maskIdentifier($paymentId),
            ], LogSanitizer::exceptionContext($e)));

            try {
                throw new BillingExceptionClass('Mollie webhook processing error', 'Failed to process Mollie webhook: ' . $e->getMessage(), BillingException::TYPE_WEBHOOK, $order->id, 'mollie', $paymentId, ['payment_status' => $payment->status ?? 'unknown', 'error' => $e->getMessage()], $e);
            } catch (BillingExceptionClass $billingEx) {
                // Exception is now logged, continue to return 200
            }
        }

        return $this->returnNoContent();
    }

    /**
     * Fulfill an order after successful payment.
     *
     * @param \Mollie\Api\Resources\Payment $payment
     */
    private function fulfillOrder(Request $request, Order $order, $payment): void
    {
        // Use centralized fulfillment service
        // Pass payment metadata for compatibility
        $metadata = $payment->metadata;
        $this->fulfillmentService->fulfillOrder($request, $order, $metadata);
    }

    /**
     * Determine the order type (NEW, UPGRADE, or RENEWAL).
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
            \Log::warning('Failed to fetch Mollie payment status', array_merge([
                'payment_id' => LogSanitizer::maskIdentifier($order->mollie_payment_id),
            ], LogSanitizer::exceptionContext($e)));
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

    /**
     * Dispatch PaymentFailed email event.
     */
    private function dispatchPaymentFailedEmail(Order $order, string $reason, string $processor): void
    {
        try {
            $user = $order->user;
            if (!$user) {
                \Log::warning("Cannot dispatch PaymentFailed email for order {$order->id}: user not found");
                return;
            }

            $currency = config('modules.billing.currency.code', 'USD');
            $product = Product::find($order->product_id);
            $amount = $order->amount ?? ($product ? $product->price : 0);
            $isRenewal = $order->type === Order::TYPE_REN;

            event(new \Everest\Events\Email\PaymentFailed(
                user: $user,
                amount: $amount,
                currency: $currency,
                reason: $reason,
                invoiceId: (string) $order->id,
                correlationId: \Illuminate\Support\Str::uuid()->toString(),
                paymentMethod: ucfirst($processor),
                isRenewal: $isRenewal,
            ));

            \Log::info("Dispatched PaymentFailed email for order {$order->id}");
        } catch (\Exception $e) {
            \Log::error("Failed to dispatch PaymentFailed email for order {$order->id}: " . $e->getMessage());
        }
    }
}
