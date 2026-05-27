# Phase 0 — Dead Code Removal & Critical Bug Fixes

**Risk:** Low  
**Effort:** Small (a few hours)  
**Depends on:** Nothing  
**Blocks:** Nothing, but fixes should land before Phase 3 to avoid confusion  

---

## Goals

Remove dead code, fix the non-idempotent coupon recording path, remove the
`Schema::hasTable` guard from `Coupon::usage()`, and kill deprecated methods
that will confuse future developers.

None of these changes alter public API contracts or require migration.

---

## Tasks

### 0.1 — Fix non-idempotent coupon recording in `OrderProcessorService`

**File:** `app/Services/Billing/OrderProcessorService.php`  
**Problem:** `recordCouponUsage()` calls `CouponUsage::create()` with no uniqueness
guard. If a free checkout is retried (e.g. network error → user re-submits), a second
usage row is inserted. `ServerFulfillmentService` already uses `firstOrCreate()` correctly.

**Fix:** Change `CouponUsage::create([...])` to `CouponUsage::firstOrCreate([...])` using
the same `['coupon_id', 'user_id', 'order_id']` composite key as `ServerFulfillmentService`.

**Test:** Add a unit test that calls `processRenewal()` twice with the same coupon and
asserts only one `CouponUsage` row exists.

---

### 0.2 — Remove `Schema::hasTable` guard from `Coupon::usage()`

**File:** `app/Models/Billing/Coupon.php`  
**Problem:** The `usage()` relationship wraps a `Schema::hasTable('coupon_usage')` check
in a try-catch and returns a no-op mock relationship if the table doesn't exist. This is
dead defensive code — the migration has been run in all environments. It hides errors and
makes the relationship unreliable during testing.

**Fix:** Replace the entire try-catch body with a plain `return $this->hasMany(CouponUsage::class);`.
Keep `getUsageCountAttribute()` tidy with the same simplification.

**Verify:** Run the test suite. If any test was relying on the fallback, it was masking a
missing migration or factory setup — fix the test, not the code.

---

### 0.3 — Remove dead status methods from `MolliePaymentService`

**File:** `app/Services/Billing/MolliePaymentService.php`  
**Dead methods** (each fetches the full payment just to return a single boolean):
- `isPaymentPaid()`
- `isPaymentFailed()`
- `isPaymentExpired()`
- `isPaymentCanceled()`
- `isPaymentAuthorized()`
- `isPaymentPending()`
- `isPaymentOpen()`
- `updatePaymentMetadata()` (throws unconditionally — documents Mollie limitation, but is itself dead)

**Verify before deleting:** Run a workspace-wide grep for each method name to confirm zero call sites outside the service itself. The webhook handler calls `$payment->isPaid()` etc. directly on the `Payment` object returned by `getPayment()`.

**Fix:** Delete all eight methods. Add a comment above `getPayment()` noting that callers
should invoke `->isPaid()`, `->isExpired()` etc. directly on the returned object.

---

### 0.4 — Remove deprecated `getSuspensionThresholdDays()` from `Product`

**File:** `app/Models/Billing/Product.php`  
**Problem:** The method has a `@deprecated` docblock pointing to `getSuspensionThresholdForBillingCycle()`.
If no code calls the deprecated method, delete it. If something still calls it, update
those call sites first.

**Verify:** Grep for `getSuspensionThresholdDays` across the codebase (excluding the model
definition itself). If no external callers: delete the method. If callers exist: update
them to use `getSuspensionThresholdForBillingCycle($server->billing_days)` then delete.

---

### 0.5 — Remove dead constructor injections in Mollie and PayPal controllers

**Files:**  
- `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php`  
- `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php`  

**Problem:** Both controllers inject `CreateServerService` and `OrderProcessorService`
in their constructors. Neither controller ever calls a method on either service.
All fulfillment goes through `ServerFulfillmentService`.

**Fix:** Remove the unused constructor parameters and their corresponding `use` imports.

**Verify:** Run `php artisan route:cache` and the test suite to confirm nothing breaks.

---

## Acceptance Criteria

- [ ] `CouponUsage` is now always written via `firstOrCreate()` in both free and paid paths.
- [ ] `Coupon::usage()` returns a plain `hasMany` with no schema guard.
- [ ] Eight `MolliePaymentService` status methods are gone; no external caller site exists.
- [ ] `Product::getSuspensionThresholdDays()` is gone; all callers use the replacement.
- [ ] Both Mollie and PayPal controllers have no injected-but-unused services.
- [ ] All existing unit tests pass.
