<?php

namespace Everest\Services\Billing;

use Everest\Models\Server;
use Illuminate\Http\Request;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\CouponUsage;
use Everest\Exceptions\DisplayException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Central server fulfillment service for paid orders.
 * 
 * This service centralizes the server creation/renewal logic for all payment processors
 * (Stripe, PayPal, Mollie). It ensures consistent behavior and reduces code duplication.
 * 
 * Key responsibilities:
 * - Idempotency checks to prevent duplicate order processing
 * - Routing to renewal or new server creation based on order type
 * - Recording coupon usage for completed orders
 * - Updating order status after successful fulfillment
 */
class ServerFulfillmentService
{
    public function __construct(
        private CreateServerService $serverCreation,
        private OrderProcessorService $processorService,
    ) {
    }

    /**
     * Fulfill an order by creating a server or processing a renewal.
     * 
     * This method handles the complete fulfillment process:
     * 1. Validates the order hasn't already been processed (idempotency)
     * 2. Creates a new server OR processes a renewal based on order type
     * 3. Records coupon usage if applicable
     * 4. Updates order status to processed
     * 
     * IMPORTANT: Server creation must be committed BEFORE calling Wings to ensure
     * the server exists in the database when Wings calls back to fetch configuration.
     * We use a status check to prevent duplicate processing.
     * 
     * @param Request $request The HTTP request
     * @param Order $order The order to fulfill
     * @param object|null $paymentMetadata Optional metadata from payment processor (Stripe, PayPal, Mollie)
     * @return Server The created or renewed server
     * @throws DisplayException if order is already processed or fulfillment fails
     */
    public function fulfillOrder(Request $request, Order $order, ?object $paymentMetadata = null): Server
    {
        // Refresh order to get latest status
        $order->refresh();
        
        // Idempotency check: if order is already processed, return early
        if ($order->status === Order::STATUS_PROCESSED) {
            Log::info("Order {$order->id} already processed");
            throw new DisplayException('This order has already been processed.');
        }

        try {
            $product = Product::findOrFail($order->product_id);

            Log::info("Fulfilling order {$order->id} of type {$order->type}");

            // Create server or process renewal
            // ServerCreationService has its own transaction that will be committed
            // BEFORE calling Wings, ensuring the server exists when Wings calls back
            if ($order->type === Order::TYPE_REN) {
                // RENEWAL: Use existing server
                $server = $this->processRenewal($order, $product);
            } else {
                // NEW SERVER: Create new server
                $server = $this->processNewServer($request, $order, $product, $paymentMetadata);
            }

            // Update order status and record coupon usage
            // Use a transaction for atomicity, but this is AFTER server creation
            DB::beginTransaction();
            try {
                // Check again before final update (optimistic concurrency control)
                $currentOrder = Order::where('id', $order->id)->first();
                if ($currentOrder->status === Order::STATUS_PROCESSED) {
                    DB::rollBack();
                    Log::info("Order {$order->id} was processed by another request during fulfillment");
                    // Server is created, order is marked processed - this is OK
                    return $server;
                }
                
                // Record coupon usage for non-renewal orders
                // (Renewals are handled by OrderProcessorService)
                if ($order->type !== Order::TYPE_REN && $order->coupon_id) {
                    $this->recordCouponUsage($order);
                }

                // Mark the order as processed (only for non-renewal orders)
                // Renewal orders maintain their own status lifecycle
                if ($order->type !== Order::TYPE_REN) {
                    $order->update(['status' => Order::STATUS_PROCESSED]);
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Failed to update order status for {$order->id}: " . $e->getMessage());
                // Server is created successfully, but order status update failed
                // This is not critical - the server exists and can be used
                // Log the error but don't throw - return the server
                Log::warning("Order {$order->id} server created successfully, but status update failed. Manual intervention may be needed.");
            }
            
            Log::info("Successfully fulfilled order {$order->id}, server {$server->id}");
            
            return $server;
        } catch (\Exception $e) {
            Log::error("Failed to fulfill order {$order->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process a renewal order.
     * 
     * @param Order $order The renewal order
     * @param Product $product The product to renew with
     * @return Server The renewed server
     * @throws DisplayException if server ID is missing
     */
    private function processRenewal(Order $order, Product $product): Server
    {
        // For renewals, get the server from the stored server_id
        if (!$order->server_id) {
            throw new DisplayException('Server ID not found in order record for renewal.');
        }
        
        $server = Server::findOrFail($order->server_id);

        // Use the unified processor service for renewal
        $result = $this->processorService->processRenewal($server, $product, $order->coupon_id);
        
        Log::info("Completed server renewal for order {$order->id}, server {$server->id}");
        
        return $result['server'];
    }

    /**
     * Process a new server order.
     * 
     * @param Request $request The HTTP request
     * @param Order $order The new server order
     * @param Product $product The product being purchased
     * @param object|null $paymentMetadata Optional metadata from payment processor
     * @return Server The created server
     */
    private function processNewServer(Request $request, Order $order, Product $product, ?object $paymentMetadata = null): Server
    {
        // Set the user resolver to the order owner
        // This is needed when called from webhooks where there's no authenticated user
        $user = \Everest\Models\User::findOrFail($order->user_id);
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        // Build metadata object from order data or payment metadata
        $metadata = $this->buildMetadata($order, $paymentMetadata);

        // Create the server using the centralized creation service
        $server = $this->serverCreation->process($request, $product, $metadata, $order);
        
        Log::info("Created new server {$server->id} for order {$order->id}");
        
        return $server;
    }

    /**
     * Build metadata object for server creation.
     * 
     * Prefers payment metadata if available (Stripe), otherwise uses order data (PayPal, Mollie).
     * 
     * @param Order $order The order containing server creation data
     * @param object|null $paymentMetadata Optional metadata from payment processor
     * @return object Metadata object with required fields
     */
    private function buildMetadata(Order $order, ?object $paymentMetadata = null): object
    {
        // If payment metadata is provided (Stripe), use it
        if ($paymentMetadata !== null) {
            // Create a copy to avoid modifying the original
            $metadata = clone $paymentMetadata;
            
            // Decode variables if they're JSON encoded
            if (isset($metadata->variables) && !empty($metadata->variables)) {
                if (is_string($metadata->variables)) {
                    $metadata->variables = json_decode($metadata->variables, true) ?? [];
                }
            }
            return $metadata;
        }

        // Otherwise, build from order data (PayPal, Mollie)
        return (object) [
            'product_id' => $order->product_id,
            'node_id' => $order->node_id,
            'egg_id' => $order->egg_id,
            'name' => $order->name,
            'variables' => $order->variables ?? [],
        ];
    }

    /**
     * Record coupon usage for an order.
     * 
     * Uses firstOrCreate to be idempotent and avoid race conditions.
     * 
     * @param Order $order The order to record coupon usage for
     */
    private function recordCouponUsage(Order $order): void
    {
        // Use firstOrCreate for idempotent coupon usage recording
        CouponUsage::firstOrCreate(
            ['order_id' => $order->id],
            [
                'coupon_id' => $order->coupon_id,
                'user_id' => $order->user_id,
                'used_at' => now(),
            ]
        );
        
        Log::info("Recorded coupon usage for order {$order->id}");
    }
}
