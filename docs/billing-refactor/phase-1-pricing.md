# Phase 1 — Price Calculation Consolidation

**Risk:** Low  
**Effort:** Medium (half a day)  
**Depends on:** Nothing  
**Blocks:** Phase 3 (service restructure assumes pricing is clean)

---

## Goals

Move all price calculation logic out of the `Product` model and into `BillingCycleService`.
Fix the double-calculation problem where controllers validate a price via
`BillingValidationService::calculatePriceWithCoupon()` and then `CreateOrderService::create()`
silently recalculates it from scratch.

---

## Background

Currently the call chain is:

```
Controller
  → BillingValidationService::calculatePriceWithCoupon()   ← first calculation
      → Product::calculatePrice()                           ← model does math
  → CreateOrderService::create()                            ← second calculation
      → Product::calculatePrice()  again                    ← discards validated price
```

If product pricing or coupon state changes between the two calls (rare but possible),
the order is created at a different price than what was validated and shown to the user.

---

## Tasks

### 1.1 — Move `Product::calculatePrice()` into `BillingCycleService`

**Current location:** `app/Models/Billing/Product.php` (~70 lines of pricing logic)  
**Target location:** `app/Services/Billing/BillingCycleService::calculatePrice()`

`BillingCycleService::calculatePrice()` currently just delegates:
```php
public function calculatePrice(Product $product, int $billingDays, ?int $nodeId = null): array
{
    return $product->calculatePrice($billingDays, $nodeId);
}
```

**Plan:**
1. Copy the full body of `Product::calculatePrice()` into `BillingCycleService::calculatePrice()`,
   replacing the delegation call.
2. Change `Product::calculatePrice()` to delegate to the service:
   ```php
   public function calculatePrice(int $days, ?int $nodeId = null): array
   {
       return app(BillingCycleService::class)->calculatePrice($this, $days, $nodeId);
   }
   ```
   This keeps the old call sites working through the transition without a flag day.
3. After all direct callers of `Product::calculatePrice()` have been updated to go
   through `BillingCycleService`, the delegation shim in `Product` can be removed in Phase 3.

---

### 1.2 — Thread the pre-validated price through `CreateOrderService`

**Problem:** Controllers call `BillingValidationService::calculatePriceWithCoupon()`, get
back `finalPrice`, `discount`, `subtotal`, and `billingDays`, then ignore this result
when calling `CreateOrderService::create()` which recalculates.

**Fix:** Add an optional `float $finalPrice` parameter to `CreateOrderService::create()`.
When provided, skip the internal `Product::calculatePrice()` call and use the supplied
value directly.

```php
public function create(
    ?string $paymentIntentId,
    User $user,
    Product $product,
    string $status,
    string $type,
    ?int $couponId = null,
    ?int $eggId = null,
    array $extraFields = [],
    ?float $preCalculatedTotal = null,   // ← new
    ?float $preCalculatedSubtotal = null, // ← new
    ?float $preCalculatedDiscount = null  // ← new
): Order
```

All existing callers pass nothing for these new params and continue to recalculate (safe
default). Callers that already have the validated price (i.e., `CheckoutController`,
`MollieCheckoutController`, `PayPalCheckoutController`) should pass their pre-validated
values.

**Update callers:**
- `CheckoutController::processFree()` — already calls `calculatePriceWithCoupon()`, pass result through
- `CheckoutController::processPaid()` — same
- `MollieCheckoutController::createPayment()` — same
- `PayPalCheckoutController::createOrder()` — same

---

### 1.3 — Fix analytics revenue forecast

**File:** `app/Http/Controllers/Api/Application/Billing/BillingController.php`  
**Problem:** Forecast is calculated as `$server->product->price / $server->billing_days`.
This uses the catalog base price, not the actual amount the customer is billed. Coupon
discounts and cycle multipliers are ignored.

**Fix:** Use `$server->billing_amount` (the amount stored from the last order) divided by
`$server->billing_days`:

```php
// Before
$totalDailyRevenue = $activeServers->sum(function ($server) {
    return $server->product->price / $server->billing_days;
});

// After
$totalDailyRevenue = $activeServers->sum(function ($server) {
    if (!$server->billing_days || !$server->billing_amount) return 0;
    return $server->billing_amount / $server->billing_days;
});
```

Also add `billing_amount` to the `$activeServers` filter condition:
```php
$activeServers = $servers->filter(function ($server) {
    return $server->billing_days > 0 && $server->billing_amount > 0;
});
```

---

## Acceptance Criteria

- [ ] `BillingCycleService::calculatePrice()` contains the full pricing logic (no delegation).
- [ ] `Product::calculatePrice()` delegates to `BillingCycleService` (shim, not the owner).
- [ ] `CreateOrderService::create()` accepts optional pre-calculated price params.
- [ ] All four checkout controllers pass their pre-validated prices to `CreateOrderService`.
- [ ] Analytics forecast uses `billing_amount / billing_days`.
- [ ] All existing pricing unit tests pass unchanged (behavior is identical, just relocated).
- [ ] Add a test that verifies `CreateOrderService` uses the pre-calculated price when provided.
