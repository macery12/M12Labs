# Phase 3 — Service Layer Restructure

**Risk:** High  
**Effort:** Large (1–2 days)  
**Depends on:** Phase 0, Phase 2  
**Blocks:** Phase 5

---

## Goals

Eliminate the overlapping responsibility between `OrderProcessorService` and
`ServerFulfillmentService`. Split `BillingValidationService` so that pricing logic
lives in `BillingCycleService` and Wings-side validation lives in `PlanChangeService`.
Wrap `ServerRenewalService::renew()` in a database transaction.

This is the highest-risk phase because it touches the core fulfillment path for both
free and paid orders.

---

## Background: The Two Orchestrators

```
Free checkout
  CheckoutController
    → OrderProcessorService::createServerOrder()
        → CreateOrderService
        → CreateServerService
        → CouponUsage::create()           ← non-idempotent (fixed in Phase 0)

Paid checkout (Stripe/PayPal/Mollie)
  [StripeCheckout|MollieCheckout|PayPalCheckout]Controller
    → ServerFulfillmentService::fulfillOrder()
        → OrderProcessorService::processRenewal() OR CreateServerService
        → CouponUsage::firstOrCreate()    ← idempotent
        → dispatchPaymentReceivedEmail()
```

`ServerFulfillmentService` calls `OrderProcessorService::processRenewal()` for renewals,
but handles new server creation itself by calling `CreateServerService` directly —
bypassing `OrderProcessorService::createServerOrder()`. The two services partially
overlap and partially diverge.

---

## Tasks

### 3.1 — Wrap `ServerRenewalService::renew()` in a DB transaction

**File:** `app/Services/Billing/ServerRenewalService.php`  
**Problem:** `renew()` creates an order, updates the server, updates the order status.
If the server update fails mid-way, the order is left in `pending` state but the
renewal date may not be written.

**Fix:** Wrap the body of `renew()` in `DB::transaction(function () { ... })`.

```php
public function renew(Server $server, ...): array
{
    return DB::transaction(function () use ($server, ...) {
        // existing body
    });
}
```

This is a one-line risk fix and should land before any restructure work on this service.

---

### 3.2 — Split `BillingValidationService` pricing logic into `BillingCycleService`

**File:** `app/Services/Billing/BillingValidationService.php`  
**Method to move:** `calculatePriceWithCoupon()`

`calculatePriceWithCoupon()` computes: base price → billing multiplier → node multiplier
→ coupon discount → final price. It is a pricing concern, not a validation concern.
`BillingCycleService` already owns pricing by Phase 1.

**Plan:**
1. Move `calculatePriceWithCoupon()` to `BillingCycleService` as a public method.
2. In `BillingValidationService`, add `BillingCycleService` as a dependency and delegate:
   ```php
   public function calculatePriceWithCoupon(...): array
   {
       return $this->cycleService->calculatePriceWithCoupon(...);
   }
   ```
   (Shim to avoid a flag-day change across all callers.)
3. Over time, callers can switch to `BillingCycleService` directly. The shim can be
   removed once no external callers remain on `BillingValidationService`.

---

### 3.3 — Move plan downgrade validation into `PlanChangeService`

**File:** `app/Services/Billing/BillingValidationService.php`  
**Method to move:** `validatePlanDowngrade()`

`validatePlanDowngrade()` checks if current resource usage fits within new product limits.
`PlanChangeService` already performs the cooldown check, category check, and calls
`validatePlanDowngrade()` — it is the natural owner of all plan-change validation.

**Plan:**
1. Move `validatePlanDowngrade()` to `PlanChangeService` as a private method (it's only
   called from there).
2. Remove it from `BillingValidationService`.
3. Update `PlanChangeService::changePlan()` to call `$this->validatePlanDowngrade()`
   directly instead of `$this->validationService->validatePlanDowngrade()`.
4. If `$validationService` is now only used in `PlanChangeService` for the billing-enabled
   check, add `validateBillingEnabled()` directly or keep the dependency for just that
   one call.

---

### 3.4 — Consolidate the free and paid orchestration paths

**Current:** Two independent paths (free via `OrderProcessorService`, paid via `ServerFulfillmentService`).  
**Target:** One entry point with clearly separated free/paid sub-paths.

**Plan: Promote `ServerFulfillmentService` as the single orchestrator.**

`OrderProcessorService::createServerOrder()` does:
1. `CreateOrderService::create()` → order record
2. `CreateServerService::processFree()` → server  
3. `CustomDomainProvisioningService::syncFromOrder()`
4. `ProvisionServerCustomDomainsJob::dispatch()`
5. `CouponUsage::firstOrCreate()` (after Phase 0 fix)
6. `$order->update(['status' => 'processed'])`

`ServerFulfillmentService::fulfillOrder()` does:
1. Idempotency check
2. Determine renewal vs new server
3. For renewal: `OrderProcessorService::processRenewal()` (→ `ServerRenewalService`)
4. For new server: `CreateServerService::handle()` (paid path)
5. Coupon recording
6. Order status update
7. `dispatchPaymentReceivedEmail()`

The restructure:

**Step A:** Move the free-order path out of `OrderProcessorService::createServerOrder()`
into `ServerFulfillmentService::fulfillFreeOrder()` that accepts the same arguments.
`CheckoutController::processFree()` is updated to call this instead.

**Step B:** Move `processRenewal()` body from `OrderProcessorService` into
`ServerFulfillmentService::fulfillRenewal()`.

**Step C:** `OrderProcessorService` is now empty and can be deleted. Any remaining
references (constructor injections cleaned up in Phase 0) should be zero.

**Step D:** Update `CheckoutController` to inject `ServerFulfillmentService` instead of
`OrderProcessorService`.

**Transition safety:** Do Step A and B simultaneously in a single PR. Step C and D are a
cleanup PR immediately after. Keep `OrderProcessorService` as a deprecated thin wrapper
for one release cycle if you need a safe rollback path.

---

### 3.5 — Post-restructure: what `BillingValidationService` should contain

After removing pricing and plan-change validation, `BillingValidationService` should only contain:
- `validateBillingEnabled()`
- `validateNodeSelectionForProduct()`
- `validateNodeDeployment()`
- `validateAndGetEggId()`
- `validateFreeProductOwnership()`

These are all genuinely "is this checkout allowed?" questions. Keep it as-is at that size.

---

## Acceptance Criteria

- [ ] `ServerRenewalService::renew()` is wrapped in `DB::transaction()`.
- [ ] `BillingCycleService::calculatePriceWithCoupon()` exists; `BillingValidationService` delegates to it.
- [ ] `validatePlanDowngrade()` moved to `PlanChangeService`; removed from `BillingValidationService`.
- [ ] `ServerFulfillmentService` handles both free and paid orchestration.
- [ ] `CheckoutController` calls `ServerFulfillmentService` for free orders.
- [ ] `OrderProcessorService` is empty or deleted.
- [ ] All existing unit tests pass; no fulfillment behavior changed.
- [ ] New integration test: free order and paid order both end up with a `processed` order and the correct server state.

---

## Rollback Plan

`OrderProcessorService` stays as a no-op delegation shim for one release after the
`ServerFulfillmentService` consolidation. If a regression is found, the shim is swapped
back to the original implementation in a hotfix. The `DB::transaction()` wrapping is
always safe to roll forward; it cannot cause regressions.
