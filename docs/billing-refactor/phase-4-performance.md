# Phase 4 — Performance: Node Availability & Analytics

**Risk:** Medium  
**Effort:** Medium (half a day)  
**Depends on:** Nothing (independent of other phases)  
**Blocks:** Nothing

---

## Goals

Stop `NodeAvailabilityService` from making synchronous HTTP calls to Wings on every
checkout page load. Fix the `BillingController::analytics()` N+1 query. Both are
production performance problems that do not require any data model changes.

---

## Background

### Node Availability (synchronous Wings pings)

`NodeAvailabilityService::getAvailableNodesForProduct()` is called when rendering the
checkout page. It iterates through every deployable node and calls:

```php
$this->repository->setNode($node)->getSystemInformation();
```

This is a synchronous HTTP request to each Wings daemon. With 5 nodes: 5 HTTP round-trips
blocking the initial checkout page response. If a node is slow (even healthy), every
customer checkout is delayed. If a node is unreachable, it throws and is silently skipped —
the customer never sees why a node disappeared.

### Analytics N+1

`BillingController::analytics()` calls `Server::with('product')->get()` to load all
servers, then iterates with `$server->product->price` and `$server->billing_days`. This
is fine with a small server count, but becomes slow at scale because the eager load still
pulls every server column into memory. The suspended servers query is a second full table
scan. Both run in a single web request on the admin panel.

---

## Tasks

### 4.1 — Cache node availability with short TTL

**File:** `app/Services/Billing/NodeAvailabilityService.php`

**Plan:**

Replace the inline Wings ping with a cached result. Cache key per node with a short TTL
(30 seconds is enough — customers don't expect real-time accuracy to the second).

```php
public function getAvailableNodesForProduct(Product $product): Collection
{
    $isFreeProduct = (float) $product->price === 0.00;
    $nodes = Node::where($isFreeProduct ? 'deployable_free' : 'deployable', true)->get();

    return $nodes->filter(function (Node $node) {
        return $this->isNodeAvailable($node);
    });
}

private function isNodeAvailable(Node $node): bool
{
    $cacheKey = "billing.node_available.{$node->id}";

    return Cache::remember($cacheKey, 30, function () use ($node) {
        if (!$node->allocations()->whereNull('server_id')->exists()) {
            return false;
        }
        try {
            $this->repository->setNode($node)->getSystemInformation();
            return true;
        } catch (\Throwable) {
            return false;
        }
    });
}
```

**Allocation check note:** The allocation check (`whereNull('server_id')`) should also be
cached, or it will still fire once per node per 30 seconds (which is acceptable). If the
allocation DB query becomes a bottleneck, wrap it in the same `Cache::remember`.

**Cache invalidation:** When a server is created on a node, the checkout flow should call
`Cache::forget("billing.node_available.{$node->id}")` to force a fresh check. The most
natural place is `CreateServerService` after a successful server creation.

---

### 4.2 — Add a background Wings health check command

Long-term, the Wings ping should not happen in the HTTP request at all. Add an Artisan
command that runs every minute via the scheduler:

```php
// app/Console/Commands/Billing/RefreshNodeAvailabilityCommand.php
class RefreshNodeAvailabilityCommand extends Command
{
    protected $signature = 'billing:refresh-node-availability';
    protected $description = 'Refresh Wings availability cache for all deployable nodes';

    public function handle(DaemonConfigurationRepository $repo): void
    {
        $nodes = Node::where('deployable', true)->orWhere('deployable_free', true)->get();

        foreach ($nodes as $node) {
            $available = false;
            try {
                $repo->setNode($node)->getSystemInformation();
                $available = true;
            } catch (\Throwable) {}

            Cache::put("billing.node_available.{$node->id}", $available, 90);
        }
    }
}
```

Register in `app/Console/Kernel.php`:
```php
$schedule->command('billing:refresh-node-availability')->everyMinute();
```

With the scheduler running, the 30-second `Cache::remember` in Task 4.1 becomes a
read-only cache hit on the checkout path — zero Wings HTTP calls during customer checkouts.

**Task 4.1 is required. Task 4.2 is a follow-up improvement.**

---

### 4.3 — Fix analytics N+1 and revenue calculation

**File:** `app/Http/Controllers/Api/Application/Billing/BillingController.php`  
**Method:** `analytics()`

**Problems:**
1. `Order::with('server')->get()` loads all orders into memory for the response.
   This is unbounded and will degrade over time.
2. `Server::with('product')->get()` loads all servers, then iterates filtering by dates.
   Should use database-level date filtering.
3. The revenue forecast uses `$server->product->price` instead of
   `$server->billing_amount` (fixed in Phase 1, tracked here as a dependency).

**Fix:**

```php
// Use DB date filtering instead of PHP collection filtering
$overdueRenewals = Server::whereNotNull('renewal_date')
    ->whereNotNull('billing_product_id')
    ->where('renewal_date', '<', $now)
    ->with('product')
    ->get();

$renewalsIn7Days = Server::whereNotNull('renewal_date')
    ->whereNotNull('billing_product_id')
    ->whereBetween('renewal_date', [$now, $now->copy()->addDays(7)])
    ->with('product')
    ->get();

$renewalsIn8to14Days = Server::whereNotNull('renewal_date')
    ->whereNotNull('billing_product_id')
    ->whereBetween('renewal_date', [$now->copy()->addDays(7), $now->copy()->addDays(14)])
    ->with('product')
    ->get();
```

For the `orders` load: paginate or limit to recent N orders if the full list is only
used for counting/summing — use `Order::selectRaw('count(*), sum(total), ...')` instead.

For suspended servers: add `->select(['id','uuid','name','user_id'])` to avoid loading
all columns.

---

### 4.4 — Add missing index on `servers.renewal_date`

The date-filtered queries in analytics and in the renewal scheduler both filter on
`renewal_date`. Check whether an index exists:

```php
// Check: database/migrations/ for an index on servers.renewal_date
// If missing, create:
Schema::table('servers', function (Blueprint $table) {
    $table->index('renewal_date');
});
```

Also check for an index on `orders.user_id` and `orders.mollie_payment_id` / `orders.paypal_order_id`
since those are used as lookup keys in webhook handlers under load.

---

## Acceptance Criteria

- [ ] Checkout page no longer makes synchronous Wings calls (results come from cache).
- [ ] Cache is invalidated when a server is created on a node.
- [ ] `billing:refresh-node-availability` command exists and is scheduled.
- [ ] `analytics()` uses DB-level date filtering.
- [ ] `analytics()` uses `billing_amount` for forecast (requires Phase 1 to be merged first).
- [ ] An index on `servers.renewal_date` exists.
- [ ] Manual test: checkout page loads without Wings connections (Wings offline = shows no available nodes, not a timeout).
