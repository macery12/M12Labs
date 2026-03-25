<?php

namespace Everest\Services\Billing;

use Carbon\Carbon;
use Everest\Models\Server;
use Everest\Models\Billing\Product;

class UpgradeService
{
    /**
     * Generate an OTC for server upgrade mid-term, accurate to the nearest minute.
     */
    public function charge(Server $server, Product $existing, Product $new): float
    {
        $minutes_left = now()->diffInMinutes(Carbon::parse($server->renewal_date), false);
        $renewal_minutes = config('modules.billing.renewal.days') * 24 * 60;
        $price_diff = $new->price - $existing->price;

        if ($minutes_left <= 0 || $price_diff <= 0) {
            return 0.0;
        }

        $prorated = $price_diff * ($minutes_left / $renewal_minutes);

        return round($prorated, 2);
    }

    /**
     * Validate that an upgrade can be processed according to limits.
     */
    public function validate(User $user): bool
    {
        $renewal_days = config('modules.billing.renewal.days');
        $order = $user->orders()->where(['type' => Order::TYPE_UPGRADE])->latest()->first();

        if (!$order) return true;

        if ($order->created_at->diffInDays(now()) < $renewal_days) {
            throw new DisplayException("You must wait {$renewal_days} between server upgrades.");
        }

        return true;
    }

    /**
     * Assign the new product to this server and add the additional resources.
     */
    public function handle(Server $server, Product $product): Server
    {
        $resources = [
            'billing_product_id' => $product->id,

            'cpu' => $product->cpu_limit,
            'memory' => $product->memory_limit,
            'disk' => $product->disk_limit,
            'allocation_limit' => $product->allocation_limit,
            'backup_limit' => $product->backup_limit,
            'database_limit' => $product->database_limit,
        ];

        $server->fill($resources)->save();

        return $server;
    }
}
