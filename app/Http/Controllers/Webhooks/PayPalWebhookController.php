<?php

namespace Everest\Http\Controllers\Webhooks;

use Illuminate\Http\Request;
use Everest\Models\Billing\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Everest\Services\Security\LogSanitizer;
use Everest\Services\Billing\PayPalPaymentService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Services\Billing\ServerFulfillmentService;

class PayPalWebhookController
{
    public function __construct(
        private PayPalPaymentService $paypalService,
        private BillingValidationService $validationService,
        private ServerFulfillmentService $fulfillmentService,
    ) {
    }

    /**
     * Handle PayPal webhook notifications.
     *
     * This endpoint receives asynchronous notifications from PayPal about payment events.
     * It verifies the webhook, fetches the actual payment status from PayPal API,
     * and fulfills orders for successful payments.
     *
     * Important: This endpoint is public and receives requests directly from PayPal.
     * No authentication or user context is available.
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $eventType = $request->input('event_type');
            $resource = $request->input('resource', []);

            // Extract PayPal order ID based on event type
            // Different event types have order ID in different locations
            $paypalOrderId = null;

            switch ($eventType) {
                case 'PAYMENT.CAPTURE.COMPLETED':
                case 'PAYMENT.CAPTURE.DENIED':
                case 'PAYMENT.CAPTURE.REFUNDED':
                case 'PAYMENT.CAPTURE.REVERSED':
                    // For all capture-related events, order ID is in supplementary_data
                    // These events all relate to the same order and need the same extraction logic
                    // Use safe array access to handle potentially missing nested keys
                    if (isset($resource['supplementary_data']['related_ids']['order_id'])) {
                        $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'];
                    }
                    break;

                case 'CHECKOUT.ORDER.APPROVED':
                case 'CHECKOUT.ORDER.COMPLETED':
                case 'CHECKOUT.ORDER.SAVED':
                    // For order events, ID is directly in the resource
                    $paypalOrderId = $resource['id'] ?? null;
                    break;

                default:
                    // Unsupported event type - this may be a new PayPal event we haven't implemented yet
                    // or an event not relevant to our billing flow. Return 200 to acknowledge receipt.
                    Log::warning('Unsupported PayPal webhook event type received', [
                        'event_type' => $eventType,
                        'resource_id' => $resource['id'] ?? null,
                        'resource_type' => $request->input('resource_type'),
                        'note' => 'This may be expected for certain PayPal events. Review PayPal webhook settings if unexpected.',
                    ]);

                    return response()->json(['ok' => true], 200);
            }

            if (!$paypalOrderId) {
                // Return 200 to prevent PayPal retries, but log the issue
                Log::warning('PayPal webhook: Could not extract order ID from event', [
                    'event_type' => $eventType,
                    'resource_id' => $resource['id'] ?? null,
                    'resource_type' => $request->input('resource_type'),
                ]);

                return response()->json(['ok' => true], 200);
            }

            // Find the order by paypal_order_id
            $order = Order::where('paypal_order_id', $paypalOrderId)->latest()->first();

            if (!$order) {
                // Return 200 to prevent PayPal retries for non-existent orders
                Log::warning('PayPal webhook order not found', [
                    'paypal_order_id' => LogSanitizer::maskIdentifier($paypalOrderId),
                ]);

                return response()->json(['ok' => true], 200);
            }

            // IDEMPOTENCY: Check if payment is already in a final state (processed or failed)
            // This prevents duplicate processing if webhook is called multiple times
            if (in_array($order->status, [Order::STATUS_PROCESSED, Order::STATUS_FAILED], true)) {
                Log::info("PayPal webhook: Order {$order->id} already in final state: {$order->status}");

                return response()->json(['ok' => true], 200);
            }

            // Validate billing is enabled
            $this->validationService->validateBillingEnabled();

            // SECURITY: Fetch order details from PayPal API (never trust webhook data directly)
            // This also verifies the webhook is legitimate
            $paypalOrder = $this->paypalService->getOrder($paypalOrderId);

            // Handle different order statuses according to PayPal documentation
            // https://developer.paypal.com/docs/api/orders/v2/#orders_get
            $status = $paypalOrder['status'] ?? 'UNKNOWN';

            Log::info('Processing PayPal webhook', [
                'event_type' => $eventType,
                'paypal_order_id' => LogSanitizer::maskIdentifier($paypalOrderId),
                'order_id' => $order->id,
                'paypal_status' => $status,
                'order_status' => $order->status,
            ]);

            switch ($status) {
                case 'COMPLETED':
                    // Payment captured successfully - fulfill the order
                    Log::info('PayPal webhook completed order', [
                        'paypal_order_id' => LogSanitizer::maskIdentifier($paypalOrderId),
                        'order_id' => $order->id,
                    ]);
                    $this->fulfillOrder($request, $order);
                    break;

                case 'APPROVED':
                    // Order approved but not yet captured
                    // This shouldn't happen if we auto-capture, but keep order as pending
                    Log::info('PayPal webhook approved order pending capture', [
                        'paypal_order_id' => LogSanitizer::maskIdentifier($paypalOrderId),
                        'order_id' => $order->id,
                    ]);
                    break;

                case 'VOIDED':
                case 'EXPIRED':
                    // Order voided or expired - mark as failed
                    Log::info('PayPal webhook marked order failed', [
                        'paypal_order_id' => LogSanitizer::maskIdentifier($paypalOrderId),
                        'order_id' => $order->id,
                        'paypal_status' => $status,
                    ]);
                    $order->update(['status' => Order::STATUS_FAILED]);
                    break;

                case 'CREATED':
                case 'SAVED':
                case 'PAYER_ACTION_REQUIRED':
                    // Order in progress - keep as pending
                    Log::info('PayPal webhook order still pending action', [
                        'paypal_order_id' => LogSanitizer::maskIdentifier($paypalOrderId),
                        'order_id' => $order->id,
                        'paypal_status' => $status,
                    ]);
                    break;

                default:
                    // Unknown status - log for investigation
                    Log::warning('PayPal webhook returned unknown status', [
                        'paypal_order_id' => LogSanitizer::maskIdentifier($paypalOrderId),
                        'paypal_status' => $status,
                    ]);
            }
        } catch (\Throwable $e) {
            // Log error but return 200 to prevent infinite PayPal retries
            Log::error('PayPal webhook error', LogSanitizer::exceptionContext($e));
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * Fulfill an order after successful payment.
     */
    private function fulfillOrder(Request $request, Order $order): void
    {
        // Use centralized fulfillment service
        // PayPal doesn't provide additional metadata like Mollie does
        $this->fulfillmentService->fulfillOrder($request, $order, null);
    }
}
