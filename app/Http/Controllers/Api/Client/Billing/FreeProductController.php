<?php

namespace Everest\Http\Controllers\Api\Client\Billing;

use Carbon\Carbon;
use Everest\Models\Node;
use Everest\Models\Server;
use Illuminate\Http\Request;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Coupon;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\CouponUsage;
use Everest\Exceptions\DisplayException;
use Everest\Services\Billing\CreateOrderService;
use Everest\Services\Billing\CreateServerService;
use Everest\Services\Billing\ServerRenewalService;
use Everest\Transformers\Api\Client\ServerTransformer;
use Everest\Http\Controllers\Api\Client\ClientApiController;

class FreeProductController extends ClientApiController
{
    public function __construct(
        private CreateServerService $serverCreation,
        private CreateOrderService $orderService,
        private ServerRenewalService $renewalService
    ) {
        parent::__construct();
    }

    /**
     * Process and validate the creation of a server
     * based off of a free product in the billing portal.
     */
    public function process(Request $request): array
    {
        $user = $request->user();
        $product = Product::findOrFail($request->input('product'));

        if (!config('modules.billing.enabled')) {
            throw new DisplayException('The billing module is not enabled.');
        }

        // Calculate the final price with coupon if provided
        $finalPrice = $product->price;
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        
        if ($couponId) {
            $coupon = Coupon::find($couponId);
            if ($coupon) {
                $discount = $coupon->calculateDiscount($product->price);
                $finalPrice = max(0, $product->price - $discount);
            }
        }

        // Check if the final price is free (either originally free or made free by coupon)
        if ((float) $finalPrice !== 0.0) {
            throw new DisplayException('This product is not free. Please use the payment process.');
        }

        // For originally free products, check if user already owns one
        if ((float) $product->price === 0.0 && $user->servers()->where('billing_product_id', $request->input('product'))->count() > 0) {
            throw new DisplayException('You already own one of this free product. Nice try!');
        }

        if (!Node::findOrFail($request->input('node'))->deployable_free) {
            throw new DisplayException('Free servers cannot be deployed to this node.');
        }

        // Validate egg selection
        $eggId = $request->input('egg_id') ? (int) $request->input('egg_id') : null;
        $allowedEggs = $product->category->getAllowedEggs();
        
        if ($eggId) {
            if (!in_array($eggId, $allowedEggs)) {
                throw new DisplayException('The selected egg is not allowed for this product category.');
            }
        } else {
            // Default to first allowed egg if none selected
            $eggId = $product->category->getDefaultEggId();
        }

        $order = $this->orderService->create(null, $user, $product, Order::STATUS_PENDING, Order::TYPE_NEW, $couponId, $eggId);

        $variables = $request->input('variables', []);
        $server = $this->serverCreation->processFree(
            $request,
            $product,
            $request->input('node'),
            $order,
            $variables
        );

        // Record coupon usage if a coupon was applied
        if ($couponId) {
            CouponUsage::create([
                'coupon_id' => $couponId,
                'user_id' => $user->id,
                'order_id' => $order->id,
                'used_at' => now(),
            ]);
        }

        $order->update([
            'status' => Order::STATUS_PROCESSED,
            'name' => $order->name . substr($server->uuid, 0, 8),
        ]);

        return $this->fractal->item($server)
            ->transformWith(ServerTransformer::class)
            ->toArray();
    }

    /**
     * Renew a free server by extending its renewal date.
     */
    public function renew(Request $request): array
    {
        $user = $request->user();
        $serverId = $request->input('server_id');
        $product = Product::findOrFail($request->input('product'));

        if (!config('modules.billing.enabled')) {
            throw new DisplayException('The billing module is not enabled.');
        }

        // Calculate the final price with coupon if provided
        $finalPrice = $product->price;
        $couponId = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
        
        if ($couponId) {
            $coupon = Coupon::find($couponId);
            if ($coupon) {
                $discount = $coupon->calculateDiscount($product->price);
                $finalPrice = max(0, $product->price - $discount);
            }
        }

        // Check if the final price is free (either originally free or made free by coupon)
        if ((float) $finalPrice !== 0.0) {
            throw new DisplayException('This product is not free. Please use the payment process.');
        }

        // Lookup server scoped to the authenticated user
        $server = $user->servers()->findOrFail($serverId);

        // Use the unified renewal service
        $result = $this->renewalService->renew($server, $product, $couponId);
        $server = $result['server'];
        $order = $result['order'];

        // Record coupon usage if a coupon was applied
        if ($couponId) {
            CouponUsage::create([
                'coupon_id' => $couponId,
                'user_id' => $user->id,
                'order_id' => $order->id,
                'used_at' => now(),
            ]);
        }

        return $this->fractal->item($server)
            ->transformWith(ServerTransformer::class)
            ->toArray();
    }
}
