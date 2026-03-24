<?php

namespace Everest\Http\Controllers\Webhooks;

use Illuminate\Http\Request;
use Everest\Models\Billing\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Everest\Services\Security\LogSanitizer;
use Everest\Services\Billing\MollieWebhookVerificationService;
use Everest\Services\Billing\MolliePaymentService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Services\Billing\ServerFulfillmentService;

class MollieWebhookController
{
    public function __construct(
        private MolliePaymentService $mollieService,
        private MollieWebhookVerificationService $verificationService,
        private BillingValidationService $validationService,
        private ServerFulfillmentService $fulfillmentService,
    ) {
    }

    /**
     * Handle Mollie webhook notifications.
     *
     * This endpoint receives asynchronous notifications from Mollie about payment events.
     * It verifies the webhook, fetches the actual payment status from Mollie API,
     * and fulfills orders for successful payments.
     *
     * Important: This endpoint is public and receives requests directly from Mollie.
     * No authentication or user context is available.
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $verification = $this->verificationService->validate($request);

            if (!$verification['valid']) {
                Log::warning('Rejected Mollie webhook request', array_merge([
                    'reason' => $verification['reason'],
                ], $verification['context'] ?? []));

                return response()->json(['ok' => false], $verification['status']);
            }

            $paymentId = $verification['payment_id'];
            /** @var Order $order */
            $order = $verification['order'];

            // IDEMPOTENCY: Check if payment is already in a final state (processed or failed)
            // This prevents duplicate processing if webhook is called multiple times
            if (in_array($order->status, [Order::STATUS_PROCESSED, Order::STATUS_FAILED], true)) {
                Log::info("Mollie webhook: Order {$order->id} already in final state: {$order->status}");

                return response()->json(['ok' => true], 200);
            }

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
                Log::info('Mollie webhook marked payment failed', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'order_id' => $order->id,
                ]);
                $order->update(['status' => Order::STATUS_FAILED]);
            } elseif ($payment->isExpired()) {
                // EXPIRED: Payment window expired (customer didn't complete in time)
                Log::info('Mollie webhook marked payment expired', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'order_id' => $order->id,
                ]);
                $order->update(['status' => Order::STATUS_FAILED]);
            } elseif ($payment->isCanceled()) {
                // CANCELED: Customer actively canceled the payment
                Log::info('Mollie webhook marked payment canceled', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'order_id' => $order->id,
                ]);
                $order->update(['status' => Order::STATUS_FAILED]);
            } elseif ($payment->isAuthorized()) {
                // AUTHORIZED: Payment authorized but not captured yet (Klarna, credit cards)
                // Keep as pending until captured
                Log::info('Mollie webhook payment authorized', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'order_id' => $order->id,
                ]);
                $order->update(['status' => Order::STATUS_PENDING]);
            } elseif ($payment->isPending() || $payment->isOpen()) {
                // PENDING/OPEN: Payment in progress or just created - no action needed yet
                Log::info('Mollie webhook payment still pending', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'order_id' => $order->id,
                    'payment_status' => $payment->status,
                ]);
            // Keep current status
            } else {
                // Unknown status - log for investigation
                Log::warning('Mollie webhook returned unknown status', [
                    'payment_id' => LogSanitizer::maskIdentifier($paymentId),
                    'payment_status' => $payment->status,
                ]);
            }
        } catch (\Throwable $e) {
            // Log error but return 200 to prevent infinite Mollie retries
            Log::error('Mollie webhook error', LogSanitizer::exceptionContext($e));
        }

        return response()->json(['ok' => true], 200);
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
}
