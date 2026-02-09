<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Everest\Models\Setting;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\Category;
use Everest\Models\Server;
use Carbon\Carbon;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Http\Requests\Api\Application\Billing\DeleteStripeKeysRequest;
use Everest\Http\Requests\Api\Application\Billing\GetBillingAnalyticsRequest;
use Everest\Http\Requests\Api\Application\Billing\UpdateBillingSettingsRequest;

class BillingController extends ApplicationApiController
{
    /**
     * BillingController constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Update the billing settings for the Panel.
     *
     * @throws \Throwable
     */
    public function settings(UpdateBillingSettingsRequest $request): Response
    {
        // todo(jex): use normalized request with foreach key value pairs
        Setting::set('settings::modules:billing:' . $request->input('key'), $request->input('value'));

        if (strpos($request['key'], 'keys:') !== 0) {
            Activity::event('admin:billing:update')
                ->property('settings', $request->all())
                ->description('Jexactyl billing settings were updated')
                ->log();
        }

        return $this->returnNoContent();
    }

    /**
     * Gather and return billing analytics.
     */
    public function analytics(GetBillingAnalyticsRequest $request): array
    {
        $orders = Order::with('server')->get();
        
        // Calculate upcoming renewals
        $now = Carbon::now();
        $servers = Server::whereNotNull('renewal_date')
            ->whereNotNull('billing_product_id')
            ->with('product')
            ->get();
        
        $renewalsIn7Days = $servers->filter(function ($server) use ($now) {
            return $server->renewal_date && 
                   $server->renewal_date->greaterThan($now) &&
                   $server->renewal_date->lessThanOrEqualTo($now->copy()->addDays(7));
        });
        
        $renewalsIn14Days = $servers->filter(function ($server) use ($now) {
            return $server->renewal_date && 
                   $server->renewal_date->greaterThan($now) &&
                   $server->renewal_date->lessThanOrEqualTo($now->copy()->addDays(14));
        });
        
        $expectedRevenue7Days = $renewalsIn7Days->sum(function ($server) {
            return $server->product ? $server->product->price : 0;
        });
        
        $expectedRevenue14Days = $renewalsIn14Days->sum(function ($server) {
            return $server->product ? $server->product->price : 0;
        });
        
        // Calculate forecast based on active subscriptions
        $activeServers = $servers->filter(function ($server) use ($now) {
            return $server->renewal_date && $server->renewal_date->greaterThan($now);
        });
        
        $avgDailyRevenue = $activeServers->count() > 0 
            ? $activeServers->sum(function ($server) {
                if ($server->product && $server->billing_days) {
                    return $server->product->price / $server->billing_days;
                }
                return 0;
            })
            : 0;
        
        $forecast7Days = $avgDailyRevenue * 7;
        $forecast30Days = $avgDailyRevenue * 30;
        
        // Get recent billing events (last 5 orders)
        $recentEvents = Order::with('server')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'date' => $order->created_at,
                    'type' => $order->type,
                    'status' => $order->status,
                    'payment_processor' => $order->payment_processor,
                    'total' => $order->total,
                    'server_id' => $order->server_id,
                    'server_uuid' => $order->server?->uuid,
                    'server_name' => $order->server?->name,
                ];
            });
        
        return [
            'orders' => $orders,
            'categories' => Category::all(),
            'products' => Product::all(),
            'donations' => \Everest\Models\Donation::where('status', 'completed')->get(),
            'upcomingRenewals' => [
                'in7Days' => [
                    'count' => $renewalsIn7Days->count(),
                    'expectedRevenue' => $expectedRevenue7Days,
                ],
                'in14Days' => [
                    'count' => $renewalsIn14Days->count(),
                    'expectedRevenue' => $expectedRevenue14Days,
                ],
            ],
            'forecast' => [
                'next7Days' => round($forecast7Days, 2),
                'next30Days' => round($forecast30Days, 2),
            ],
            'recentEvents' => $recentEvents,
        ];
    }

    /**
     * Delete all Stripe API keys saved to the Panel.
     */
    public function resetKeys(DeleteStripeKeysRequest $request): Response
    {
        Setting::forget('settings::modules:billing:keys:publishable');
        Setting::forget('settings:modules:billing:keys:secret');

        Activity::event('admin:billing:reset-keys')
            ->description('Stripe API keys for billing were reset')
            ->log();

        return $this->returnNoContent();
    }
}
