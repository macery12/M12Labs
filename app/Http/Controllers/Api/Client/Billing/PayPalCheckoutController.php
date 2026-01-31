<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Everest\Models\Billing\Order;
use Illuminate\Http\JsonResponse;
use Everest\Models\Billing\Product;
use Everest\Exceptions\DisplayException;
use Everest\Services\Billing\CreateOrderService;
use Everest\Services\Billing\CreateServerService;
use Everest\Services\Billing\OrderProcessorService;
use Everest\Services\Billing\PayPalPaymentService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Services\Billing\ServerFulfillmentService;
use Everest\Http\Controllers\Api\Client\ClientApiController;

class PayPalCheckoutController extends ClientApiController
{
    public function __construct(
        private PayPalPaymentService $paypalService,
        private BillingValidationService $validationService,
        private OrderProcessorService $processorService,
        private CreateOrderService $orderService,
        private CreateServerService $serverCreation,
        private ServerFulfillmentService $fulfillmentService,
    ) {
        parent::__construct();
    }

    /**
     * Create a PayPal order.
     *
     * @param Request $request
     * @param int $id Product ID
     * @return JsonResponse
     */
    public function createOrder(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        // Check if this is a renewal payment
        $isRenewal = $request->boolean('renewal', false);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;

        // Determine order type and calculate price
        $orderType = $isRenewal ? Order::TYPE_REN : Order::TYPE_NEW;
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $priceInfo = $this->validationService->calculatePriceWithCoupon(
            $product,
            $couponId,
            $isRenewal ? 'renewal' : 'new'
        );

        // Validate this is not a free order
        $this->validationService->validatePriceType($priceInfo['finalPrice'], false);

        // Generate a secure random token for order tracking
        $token = \Illuminate\Support\Str::uuid()->toString();

        // Setup return and cancel URLs
        $baseReturnUrl = $request->input('return_url', url('/account/billing/processing'));
        $returnUrl = str_contains($baseReturnUrl, '?')
            ? $baseReturnUrl . '&token=' . $token . '&processor=paypal'
            : $baseReturnUrl . '?token=' . $token . '&processor=paypal';
        
        $cancelUrl = url('/account/billing/cancel');

        // Create PayPal order
        $paypalOrder = $this->paypalService->createOrder(
            $product,
            $priceInfo['finalPrice'],
            $couponId,
            $returnUrl,
            $cancelUrl
        );

        // Store order in database
        $orderData = [
            'payment_processor' => 'paypal',
            'paypal_order_id' => $paypalOrder['id'],
            'payment_token' => $token,
            'name' => $isRenewal ? 'Server Renewal' : 'Pending',
            'node_id' => null,
            'server_id' => $isRenewal ? $serverId : null,
            'variables' => [],
        ];

        $this->orderService->create(
            null, // PayPal doesn't use payment_intent_id
            $request->user(),
            $product,
            Order::STATUS_PENDING,
            $orderType,
            $couponId,
            null, // egg_id will be set in updateOrder for new orders
            $orderData
        );

        // Get approval URL for redirect
        $approvalUrl = $this->paypalService->getApprovalUrl($paypalOrder);

        return response()->json([
            'id' => $paypalOrder['id'],
            'token' => $token,
            'approval_url' => $approvalUrl,
        ]);
    }

    /**
     * Update a PayPal order with details.
     *
     * @param Request $request
     * @param int $id Product ID
     * @return Response
     */
    public function updateOrder(Request $request, int $id): Response
    {
        $product = Product::findOrFail($id);
        $paypalOrderId = $request->input('order_id');

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

        // Determine order type
        $orderType = $this->getOrderType($request);
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        $variables = $request->input('variables', []);
        $serverId = $request->input('server_id') ? (int) $request->input('server_id') : null;

        // Find the existing pending order and update it
        $order = Order::where('paypal_order_id', $paypalOrderId)
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

        Log::info("PayPal order updated successfully", [
            'order_id' => $order->id,
            'paypal_order_id' => $paypalOrderId,
            'updated_data' => [
                'name' => $serverName,
                'node_id' => $nodeId,
                'egg_id' => $eggId,
                'variables' => $variables,
            ],
        ]);

        return $this->returnNoContent();
    }

    /**
     * Capture a PayPal order after customer approval.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function captureOrder(Request $request): JsonResponse
    {
        // IMMEDIATE TEST LOG - This should ALWAYS appear if endpoint is hit
        Log::info("=== PAYPAL CAPTURE ENDPOINT HIT ===", [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'user_id' => $request->user()->id ?? 'not authenticated',
            'all_input' => $request->all(),
        ]);
        
        $paypalOrderId = $request->input('order_id');
        
        Log::info("PayPal capture requested", [
            'paypal_order_id' => $paypalOrderId,
            'user_id' => $request->user()->id,
        ]);
        
        if (!$paypalOrderId) {
            Log::error("PayPal capture failed: No order ID provided");
            throw new DisplayException('PayPal order ID is required.');
        }

        // Find our order record
        $order = Order::where('paypal_order_id', $paypalOrderId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        Log::info("Found order for capture", [
            'order_id' => $order->id,
            'order_status' => $order->status,
            'paypal_order_id' => $paypalOrderId,
        ]);

        // Check idempotency - already processed?
        if ($order->status === Order::STATUS_PROCESSED) {
            Log::info("Order already processed, returning success", ['order_id' => $order->id]);
            return response()->json([
                'success' => true,
                'message' => 'Order already processed',
                'order_id' => $order->id,
            ]);
        }

        // Verify the PayPal order is approved
        $isApproved = $this->paypalService->isOrderApproved($paypalOrderId);
        Log::info("PayPal order approval status", [
            'paypal_order_id' => $paypalOrderId,
            'is_approved' => $isApproved,
        ]);
        
        if (!$isApproved) {
            Log::warning("PayPal order not approved yet", ['paypal_order_id' => $paypalOrderId]);
            throw new DisplayException('PayPal order is not approved yet.');
        }

        // Capture the payment
        Log::info("Attempting to capture PayPal payment", ['paypal_order_id' => $paypalOrderId]);
        $captureResult = $this->paypalService->captureOrder($paypalOrderId);
        
        // Verify capture was successful
        $captureStatus = $captureResult['status'] ?? '';
        Log::info("PayPal capture result", [
            'paypal_order_id' => $paypalOrderId,
            'capture_status' => $captureStatus,
        ]);
        
        if ($captureStatus !== 'COMPLETED') {
            Log::error("PayPal capture failed", [
                'paypal_order_id' => $paypalOrderId,
                'expected_status' => 'COMPLETED',
                'actual_status' => $captureStatus,
            ]);
            throw new DisplayException('Failed to capture PayPal payment: ' . $captureStatus);
        }

        // Extract and save PayPal transaction details
        $purchaseUnit = $captureResult['purchase_units'][0] ?? null;
        $capture = $purchaseUnit['payments']['captures'][0] ?? null;
        $payer = $captureResult['payer'] ?? null;
        
        if ($capture) {
            $order->paypal_capture_id = $capture['id'] ?? null;
            $order->paypal_status = $capture['status'] ?? null;
            $order->paypal_amount = isset($capture['amount']['value']) ? (float) $capture['amount']['value'] : null;
            $order->paypal_currency = $capture['amount']['currency_code'] ?? null;
            $order->paypal_captured_at = isset($capture['create_time']) ? \Carbon\Carbon::parse($capture['create_time']) : null;
        }
        
        if ($payer) {
            $order->paypal_payer_id = $payer['payer_id'] ?? null;
            $order->paypal_payer_email = $payer['email_address'] ?? null;
        }
        
        $order->save();
        
        Log::info("Saved PayPal transaction details", [
            'order_id' => $order->id,
            'capture_id' => $order->paypal_capture_id,
            'payer_email' => $order->paypal_payer_email,
            'amount' => $order->paypal_amount,
            'currency' => $order->paypal_currency,
        ]);

        // Fulfill the order
        Log::info("Starting order fulfillment", ['order_id' => $order->id]);
        try {
            $this->fulfillOrder($request, $order);
            Log::info("Order fulfillment completed successfully", ['order_id' => $order->id]);
        } catch (\Exception $e) {
            Log::error("Order fulfillment failed", [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        // Reload order to get updated status
        $order->refresh();
        Log::info("Final order status after fulfillment", [
            'order_id' => $order->id,
            'status' => $order->status,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order processed successfully',
            'order_id' => $order->id,
        ]);
    }

    /**
     * Check the status of a PayPal order.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkOrderStatus(Request $request): JsonResponse
    {
        $paypalOrderId = $request->input('order_id');
        
        Log::info("PayPal order status check requested", [
            'paypal_order_id' => $paypalOrderId,
            'user_id' => $request->user()->id,
        ]);
        
        if (!$paypalOrderId) {
            // Fallback: Get the latest PayPal order for this user
            $order = Order::where('user_id', $request->user()->id)
                ->where('payment_processor', 'paypal')
                ->latest()
                ->first();
        } else {
            // Get the order by paypal_order_id
            $order = Order::where('paypal_order_id', $paypalOrderId)
                ->where('user_id', $request->user()->id)
                ->first();
        }

        if (!$order) {
            Log::warning("PayPal status check: Order not found", [
                'paypal_order_id' => $paypalOrderId,
                'user_id' => $request->user()->id,
            ]);
            return response()->json([
                'processed' => false,
                'failed' => false,
                'pending' => true,
                'order_id' => $paypalOrderId,
                'order_status' => 'unknown',
            ]);
        }

        // Get current order status from PayPal
        $orderStatus = 'unknown';
        try {
            if ($order->paypal_order_id) {
                $orderStatus = $this->paypalService->getOrderStatus($order->paypal_order_id);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to fetch PayPal order status for {$order->paypal_order_id}: " . $e->getMessage());
        }

        // Map order status
        $processed = $order->status === Order::STATUS_PROCESSED;
        $failed = $order->status === Order::STATUS_FAILED;
        $pending = !$processed && !$failed;

        Log::info("PayPal order status check result", [
            'order_id' => $order->id,
            'paypal_order_id' => $order->paypal_order_id,
            'internal_status' => $order->status,
            'paypal_status' => $orderStatus,
            'processed' => $processed,
            'failed' => $failed,
            'pending' => $pending,
        ]);

        return response()->json([
            'processed' => $processed,
            'failed' => $failed,
            'pending' => $pending,
            'order_id' => $order->paypal_order_id,
            'order_status' => $orderStatus,
            'internal_order_id' => $order->id,
        ]);
    }

    /**
     * Get order details from token.
     *
     * @param Request $request
     * @param string $token
     * @return JsonResponse
     */
    public function getOrderFromToken(Request $request, string $token): JsonResponse
    {
        $order = Order::where('payment_token', $token)
            ->where('user_id', $request->user()->id)
            ->where('payment_processor', 'paypal')
            ->firstOrFail();

        return response()->json([
            'order_id' => $order->paypal_order_id,
            'status' => $order->status,
            'product_id' => $order->product_id,
        ]);
    }

    /**
     * Fulfill an order after successful payment.
     *
     * @param Request $request
     * @param Order $order
     * @return void
     */
    private function fulfillOrder(Request $request, Order $order): void
    {
        // Use centralized fulfillment service
        $this->fulfillmentService->fulfillOrder($request, $order);
    }

    /**
     * Determine the order type (NEW or RENEWAL).
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
     * Process PayPal webhook notifications.
     * 
     * This endpoint receives asynchronous notifications from PayPal about payment events.
     * It verifies the webhook, fetches the actual payment status from PayPal API,
     * and fulfills orders for successful payments.
     * 
     * Important: This route is outside authentication middleware as PayPal calls it directly.
     * 
     * @param Request $request
     * @return Response
     */
    public function processPayment(Request $request): Response
    {
        $eventType = $request->input('event_type');
        $resource = $request->input('resource', []);
        
        // Log webhook event (excluding sensitive data)
        Log::info("PayPal webhook received", [
            'event_type' => $eventType,
            'resource_type' => $request->input('resource_type'),
            'resource_id' => $resource['id'] ?? null,
            'resource_status' => $resource['status'] ?? null,
        ]);

        // Extract PayPal order ID based on event type
        // Different event types have order ID in different locations
        $paypalOrderId = null;
        
        switch ($eventType) {
            case 'PAYMENT.CAPTURE.COMPLETED':
            case 'PAYMENT.CAPTURE.DENIED':
            case 'PAYMENT.CAPTURE.REFUNDED':
            case 'PAYMENT.CAPTURE.REVERSED':
                // For capture events, order ID is in supplementary_data
                $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
                Log::info("Extracted order ID from capture event", [
                    'event_type' => $eventType,
                    'capture_id' => $resource['id'] ?? null,
                    'order_id' => $paypalOrderId,
                ]);
                break;

            case 'CHECKOUT.ORDER.APPROVED':
            case 'CHECKOUT.ORDER.COMPLETED':
            case 'CHECKOUT.ORDER.SAVED':
                // For order events, ID is directly in the resource
                $paypalOrderId = $resource['id'] ?? null;
                Log::info("Extracted order ID from order event", [
                    'event_type' => $eventType,
                    'order_id' => $paypalOrderId,
                ]);
                break;

            default:
                Log::warning("Unsupported PayPal webhook event type", [
                    'event_type' => $eventType,
                    'resource_id' => $resource['id'] ?? null,
                    'resource_type' => $request->input('resource_type'),
                ]);
                return $this->returnNoContent();
        }

        if (!$paypalOrderId) {
            // Return 200 to prevent PayPal retries, but log the issue
            Log::warning('PayPal webhook: Could not extract order ID from event', [
                'event_type' => $eventType,
                'resource_id' => $resource['id'] ?? null,
                'resource_type' => $request->input('resource_type'),
            ]);
            return $this->returnNoContent();
        }

        // Find the order by paypal_order_id
        $order = Order::where('paypal_order_id', $paypalOrderId)->latest()->first();

        if (!$order) {
            // Return 200 to prevent PayPal retries for non-existent orders
            Log::warning("PayPal webhook: Order not found for PayPal order ID: {$paypalOrderId}");
            return $this->returnNoContent();
        }

        // IDEMPOTENCY: Check if payment is already in a final state (processed or failed)
        // This prevents duplicate processing if webhook is called multiple times
        if (in_array($order->status, [Order::STATUS_PROCESSED, Order::STATUS_FAILED], true)) {
            Log::info("PayPal webhook: Order {$order->id} already in final state: {$order->status}");
            return $this->returnNoContent();
        }

        try {
            // Validate billing is enabled
            $this->validationService->validateBillingEnabled();

            // SECURITY: Fetch order details from PayPal API (never trust webhook data directly)
            // This also verifies the webhook is legitimate
            $paypalOrder = $this->paypalService->getOrder($paypalOrderId);

            // Handle different order statuses according to PayPal documentation
            // https://developer.paypal.com/docs/api/orders/v2/#orders_get
            $status = $paypalOrder['status'] ?? 'UNKNOWN';

            Log::info("Processing PayPal webhook", [
                'event_type' => $eventType,
                'paypal_order_id' => $paypalOrderId,
                'order_id' => $order->id,
                'paypal_status' => $status,
                'order_status' => $order->status,
            ]);

            switch ($status) {
                case 'COMPLETED':
                    // Payment captured successfully - fulfill the order
                    Log::info("PayPal order {$paypalOrderId} completed for order {$order->id}");
                    $this->fulfillOrder($request, $order);
                    break;

                case 'APPROVED':
                    // Order approved but not yet captured
                    // This shouldn't happen if we auto-capture, but keep order as pending
                    Log::info("PayPal order {$paypalOrderId} approved but not captured for order {$order->id}");
                    break;

                case 'VOIDED':
                case 'EXPIRED':
                    // Order voided or expired - mark as failed
                    Log::info("PayPal order {$paypalOrderId} {$status} for order {$order->id}");
                    $order->update(['status' => Order::STATUS_FAILED]);
                    break;

                case 'CREATED':
                case 'SAVED':
                case 'PAYER_ACTION_REQUIRED':
                    // Order in progress - keep as pending
                    Log::info("PayPal order {$paypalOrderId} status {$status} for order {$order->id}");
                    break;

                default:
                    // Unknown status - log for investigation
                    Log::warning("PayPal order {$paypalOrderId} has unknown status: {$status}");
            }
        } catch (\Exception $e) {
            // Log error but return 200 to prevent infinite PayPal retries
            Log::error("PayPal webhook error for order {$paypalOrderId}: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $this->returnNoContent();
    }
}
