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
use Everest\Services\Billing\PayPalPaymentService;
use Everest\Services\Billing\BillingValidationService;
use Everest\Http\Controllers\Api\Client\ClientApiController;

class PayPalCheckoutController extends ClientApiController
{
    public function __construct(
        private PayPalPaymentService $paypalService,
        private BillingValidationService $validationService,
        private OrderProcessorService $processorService,
        private CreateOrderService $orderService,
        private CreateServerService $serverCreation,
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
            ? $baseReturnUrl . '&token=' . $token
            : $baseReturnUrl . '?token=' . $token;
        
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
        $paypalOrderId = $request->input('order_id');
        
        if (!$paypalOrderId) {
            throw new DisplayException('PayPal order ID is required.');
        }

        // Find our order record
        $order = Order::where('paypal_order_id', $paypalOrderId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Check idempotency - already processed?
        if ($order->status === Order::STATUS_PROCESSED) {
            return response()->json([
                'success' => true,
                'message' => 'Order already processed',
                'order_id' => $order->id,
            ]);
        }

        // Verify the PayPal order is approved
        if (!$this->paypalService->isOrderApproved($paypalOrderId)) {
            throw new DisplayException('PayPal order is not approved yet.');
        }

        // Capture the payment
        $captureResult = $this->paypalService->captureOrder($paypalOrderId);
        
        // Verify capture was successful
        $captureStatus = $captureResult['status'] ?? '';
        if ($captureStatus !== 'COMPLETED') {
            throw new DisplayException('Failed to capture PayPal payment: ' . $captureStatus);
        }

        // Fulfill the order
        $this->fulfillOrder($request, $order);

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
            \Log::warning("Failed to fetch PayPal order status for {$order->paypal_order_id}: " . $e->getMessage());
        }

        // Map order status
        $processed = $order->status === Order::STATUS_PROCESSED;
        $failed = $order->status === Order::STATUS_FAILED;
        $pending = !$processed && !$failed;

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
        // Double-check idempotency before fulfillment
        if ($order->status === Order::STATUS_PROCESSED) {
            \Log::info("Order {$order->id} already processed, skipping fulfillment");
            return;
        }

        $product = Product::findOrFail($order->product_id);

        \Log::info("Fulfilling PayPal order {$order->id} for PayPal order {$order->paypal_order_id}");

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
                'product_id' => $order->product_id,
                'node_id' => $order->node_id,
                'egg_id' => $order->egg_id,
                'name' => $order->name,
                'variables' => $order->variables ?? [],
            ];

            $server = $this->serverCreation->process($request, $product, $orderMetadata, $order);
            
            \Log::info("Created new server {$server->id} for order {$order->id}");
        }

        // Record coupon usage for non-renewal orders
        if ($order->type !== Order::TYPE_REN && $order->coupon_id) {
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

        // Mark the order as processed
        if ($order->type !== Order::TYPE_REN) {
            $order->update(['status' => Order::STATUS_PROCESSED]);
        }
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
}
