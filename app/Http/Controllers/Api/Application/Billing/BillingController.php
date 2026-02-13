<?php

namespace Everest\Http\Controllers\Api\Application\Billing;

use Carbon\Carbon;
use Everest\Models\Server;
use Everest\Models\Setting;
use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\Product;
use Everest\Models\Billing\Category;
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

        // Overdue renewals (past due - need attention)
        $overdueRenewals = $servers->filter(function ($server) use ($now) {
            return $server->renewal_date &&
                   $server->renewal_date->lessThan($now);
        });

        // Renewals in next 7 days (0-7 days)
        $renewalsIn7Days = $servers->filter(function ($server) use ($now) {
            return $server->renewal_date &&
                   $server->renewal_date->greaterThanOrEqualTo($now) &&
                   $server->renewal_date->lessThanOrEqualTo($now->copy()->addDays(7));
        });

        // Renewals in days 8-14 (not cumulative - excludes first 7 days)
        $renewalsIn8to14Days = $servers->filter(function ($server) use ($now) {
            return $server->renewal_date &&
                   $server->renewal_date->greaterThan($now->copy()->addDays(7)) &&
                   $server->renewal_date->lessThanOrEqualTo($now->copy()->addDays(14));
        });

        $expectedRevenueOverdue = $overdueRenewals->sum(function ($server) {
            return $server->product ? $server->product->price : 0;
        });

        $expectedRevenue7Days = $renewalsIn7Days->sum(function ($server) {
            return $server->product ? $server->product->price : 0;
        });

        $expectedRevenue8to14Days = $renewalsIn8to14Days->sum(function ($server) {
            return $server->product ? $server->product->price : 0;
        });

        // Total for all renewals in next 14 days (including overdue)
        $totalRenewalsIn14Days = $overdueRenewals->count() + $renewalsIn7Days->count() + $renewalsIn8to14Days->count();
        $totalExpectedRevenue14Days = $expectedRevenueOverdue + $expectedRevenue7Days + $expectedRevenue8to14Days;

        // Calculate forecast based on ALL active billing servers (including past-due)
        // A server with a billing subscription is still generating revenue even if past due
        $activeServers = $servers->filter(function ($server) {
            return $server->product &&
                   $server->billing_days &&
                   $server->billing_days > 0;
        });

        // Calculate total daily revenue from all active subscriptions
        $totalDailyRevenue = $activeServers->sum(function ($server) {
            return $server->product->price / $server->billing_days;
        });

        $forecast7Days = $totalDailyRevenue * 7;
        $forecast30Days = $totalDailyRevenue * 30;

        // Get suspended servers with details
        $suspendedServers = Server::where('status', Server::STATUS_SUSPENDED)
            ->with('user')
            ->get()
            ->map(function ($server) {
                return [
                    'id' => $server->id,
                    'uuid' => $server->uuid,
                    'name' => $server->name,
                    'owner' => $server->user ? $server->user->username : 'Unknown',
                    'owner_email' => $server->user ? $server->user->email : null,
                ];
            });

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
                'overdue' => [
                    'count' => $overdueRenewals->count(),
                    'expectedRevenue' => $expectedRevenueOverdue,
                ],
                'in7Days' => [
                    'count' => $renewalsIn7Days->count(),
                    'expectedRevenue' => $expectedRevenue7Days,
                ],
                'in8to14Days' => [
                    'count' => $renewalsIn8to14Days->count(),
                    'expectedRevenue' => $expectedRevenue8to14Days,
                ],
                'total14Days' => [
                    'count' => $totalRenewalsIn14Days,
                    'expectedRevenue' => $totalExpectedRevenue14Days,
                ],
            ],
            'forecast' => [
                'next7Days' => round($forecast7Days, 2),
                'next30Days' => round($forecast30Days, 2),
            ],
            'suspendedServers' => $suspendedServers,
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
