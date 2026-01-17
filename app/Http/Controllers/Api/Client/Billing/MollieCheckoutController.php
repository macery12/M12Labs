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

        // Use the return_url from frontend request (includes payment_id parameter placeholder)
        // We'll replace {payment_id} with the actual payment ID after creation
        $returnUrl = $request->input('return_url', url('/account/billing/processing'));
        
        $payment = $this->mollieService->createPayment(
            $product,
            $priceInfo['finalPrice'],
            $couponId,
            $returnUrl
        );

        // Add payment_id to the return URL
        $finalReturnUrl = str_contains($returnUrl, '?')
            ? $returnUrl . '&payment_id=' . $payment->id
            : $returnUrl . '?payment_id=' . $payment->id;

        // Return payment info with updated return URL
        return response()->json([
            'id' => $payment->id,
            'checkout_url' => $payment->getCheckoutUrl(),
            'return_url' => $finalReturnUrl,
        ]);
    }

    /**
     * Update a payment with order details.
     *
     * @param Request $request
     * @param int|null $id Product ID
     * @return Response
     */
    public function updatePayment(Request $request, ?int $id = null): Response
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

        // Create the order with coupon and egg
        $this->orderService->create(
            $paymentId,
            $request->user(),
            $product,
            Order::STATUS_PENDING,
            $orderType,
            $couponId,
            $eggId,
            [
                'payment_processor' => 'mollie',
                'mollie_payment_id' => $paymentId,
                'name' => $serverName,
                'node_id' => $nodeId,
                'server_id' => $serverId,
                'variables' => $variables,
            ]
        );

        return $this->returnNoContent();
    }

    /**
     * Process a Mollie payment (webhook handler).
     *
     * @param Request $request
     * @return Response
     */
    public function processPayment(Request $request): Response
    {
        $paymentId = $request->input('id');

        if (!$paymentId) {
            throw new DisplayException('Payment ID is required.');
        }

        // Find the order by mollie_payment_id
        $order = Order::where('mollie_payment_id', $paymentId)->latest()->first();

        if (!$order) {
            throw new DisplayException('Order not found for this payment.');
        }

        // Check if payment is already processed
        if ($order->status === Order::STATUS_PROCESSED) {
            return $this->returnNoContent();
        }

        // Validate billing is enabled
        $this->validationService->validateBillingEnabled();

        // Check if the payment was successful
        if ($this->mollieService->isPaymentPaid($paymentId)) {
            // Payment is successful, process the order
            $payment = $this->mollieService->getPayment($paymentId);
            $metadata = $payment->metadata;

            $product = Product::findOrFail($metadata->product_id);

            // Process the renewal or product purchase
            if ($order->type === Order::TYPE_REN) {
                // For renewals, get the server from the stored server_id
                if (!$order->server_id) {
                    throw new DisplayException('Server ID not found in order record for renewal.');
                }
                
                $server = Server::findOrFail($order->server_id);

                // Use the unified processor service for renewal
                $result = $this->processorService->processRenewal($server, $product, $order->coupon_id);
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
            }

            // Record coupon usage for non-renewal orders
            if ($order->type !== Order::TYPE_REN && $order->coupon_id) {
                CouponUsage::create([
                    'coupon_id' => $order->coupon_id,
                    'user_id' => $order->user_id,
                    'order_id' => $order->id,
                    'used_at' => now(),
                ]);
            }

            // Mark the order as processed
            if ($order->type !== Order::TYPE_REN) {
                $order->update(['status' => Order::STATUS_PROCESSED]);
            }
        } elseif ($this->mollieService->isPaymentFailed($paymentId)) {
            // Payment failed
            $order->update(['status' => Order::STATUS_FAILED]);
        }

        return $this->returnNoContent();
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
            ]);
        }

        return response()->json([
            'processed' => $order->status === Order::STATUS_PROCESSED,
            'failed' => $order->status === Order::STATUS_FAILED,
            'pending' => $order->status === Order::STATUS_PENDING,
            'payment_id' => $order->mollie_payment_id,
        ]);
    }
}
