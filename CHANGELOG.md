# Billing System Refactor

## Summary

Full refactor of the billing subsystem across 7 phases: cleanup of dead code, pricing consolidation, controller deduplication, service layer hardening, performance improvements, a new `payment_transactions` data model, and frontend UX fixes.

---

## Phase 0 — Cleanup

- **`CouponUsage`** — replaced `create()` with `firstOrCreate()` to prevent duplicate coupon usage records on retry.
- **`Coupon`** — removed `Schema::hasTable` guard on the `usage()` relationship; simplified coupon methods.
- **`MolliePaymentService`** — removed 8 dead payment status methods that were no longer called.
- **`Product`** — removed deprecated `getSuspensionThresholdDays()` method.
- **`MollieCheckoutController` / `PayPalCheckoutController`** — removed dead injections.

---

## Phase 1 — Pricing Consolidation

- Moved the full `calculatePrice()` logic into `BillingCycleService`; `Product` now delegates to it rather than duplicating the calculation.
- `CreateOrderService::create()` now accepts optional `preCalculatedTotal`, `preCalculatedSubtotal`, and `preCalculatedDiscount` parameters so controllers can pass pre-validated prices and avoid the silent double-calculation bug.
- All three checkout controllers (Stripe, Mollie, PayPal) now pass pre-validated prices from `calculatePriceWithCoupon()` directly into `orderService->create()`.
- Fixed analytics revenue forecast in `BillingController::analytics()` to use `billing_amount / billing_days` (actual charged amount) instead of `product->price`.

---

## Phase 2 — Controller Deduplication

- **`Order::resolveTypeFromRequest(Request $request): string`** — new static method on the `Order` model. Removed the duplicated private `getOrderType()` method from all three checkout controllers.
- **`ServerFulfillmentService::dispatchPaymentFailedEmail()`** — extracted into a public service method. Removed the duplicated private method from `MollieCheckoutController` and `PayPalCheckoutController`.
- **`UpdateCheckoutRequest`** — new FormRequest (`app/Http/Requests/Api/Client/Billing/UpdateCheckoutRequest.php`) with typed accessors. `updateIntent()`, `updatePayment()`, and `updateOrder()` now use it instead of raw `Request`.
- **`BillingDefaults::defaultBillingDays()`** — new helper class (`app/Services/Billing/BillingDefaults.php`) that reads from `Setting`. Replaced ~12 hardcoded `30` defaults across controllers and services.

---

## Phase 3 — Service Layer

- **`ServerRenewalService::renew()`** — wrapped in `DB::transaction()` to ensure atomicity.
- **`calculatePriceWithCoupon()`** — moved from `BillingValidationService` to `BillingCycleService` (natural home). `BillingValidationService` retains a delegation shim for backwards compatibility.
- **`validatePlanDowngrade()`** — moved from `BillingValidationService` to `PlanChangeService` with its own `DaemonServerRepository` injection. Eliminates circular dependency risk.
- **`ServerFulfillmentService::fulfillFreeOrder()`** — new public method that fully orchestrates free order fulfillment (create order, create server, sync domains, dispatch domain job, record coupon usage, mark order processed). `CheckoutController::processFree()` now calls this instead of `OrderProcessorService`. `OrderProcessorService` marked `@deprecated`.

---

## Phase 4 — Performance

- **`NodeAvailabilityService`** — each Wings ping is now wrapped in `Cache::remember("billing.node_available.{$node->id}", 30, ...)`. Checkout page no longer makes synchronous HTTP calls to Wings on every load.
- **`CreateServerService`** — calls `Cache::forget("billing.node_available.{$node->id}")` after successful server creation to force a fresh availability check.
- **`billing:refresh-node-availability`** — new Artisan command (`app/Console/Commands/Billing/RefreshNodeAvailabilityCommand.php`) that proactively refreshes Wings availability for all deployable nodes with a 90-second TTL. Registered in `Kernel.php` with `->everyMinute()->withoutOverlapping()`.
- **`BillingController::analytics()`** — replaced PHP-level collection filtering with DB-level `where` / `whereBetween` queries for `$overdueRenewals`, `$renewalsIn7Days`, and `$renewalsIn8to14Days`. Active servers for the revenue forecast also use a direct DB query.
- **New migration** `2026_05_25_000001_add_billing_indexes.php` — adds indexes on `servers.renewal_date`, `orders.user_id`, `orders.mollie_payment_id`, and `orders.paypal_order_id`.

---

## Phase 5 — Data Model

- **New migration** `2026_05_25_000002_create_payment_transactions_table.php` — creates `payment_transactions` table with processor-agnostic columns (`processor`, `external_id`, `capture_id`, `status`, `amount`, `currency`, `payer_id`, `payer_email`, `payment_token`, `raw_metadata`, `captured_at`). Indexed on `[processor, external_id]` and `payment_token`.
- **New migration** `2026_05_25_000003_migrate_orders_to_payment_transactions.php` — data migration that populates `payment_transactions` from existing Stripe, Mollie, and PayPal order rows. Runs in a transaction; uses `insertOrIgnore` and chunks of 500 for safety. Does **not** drop old columns (dual-write rollout — see `docs/billing-refactor/phase-5-data-model.md`).
- **`PaymentTransaction` model** (`app/Models/Billing/PaymentTransaction.php`) — Eloquent model with fillable columns, `datetime` / `array` casts, and a `belongsTo(Order::class)` relationship.
- **`Order::transaction()`** — new `HasOne` relationship to `PaymentTransaction`.

---

## Phase 6 — Frontend

- **`ProcessorSelectorGrid`** (`resources/scripts/components/billing/ProcessorSelectorGrid.tsx`) — new shared component for the processor selection card grid (Stripe / Mollie / PayPal). Removes ~150 lines of duplicated JSX that existed identically in `PaymentMethodSelector.tsx` (account checkout) and `PaymentContainer.tsx` (server renewal).
- **`useCheckoutDraft`** hook (`resources/scripts/hooks/useCheckoutDraft.ts`) — persists checkout configuration (node, egg, billing cycle, coupon, server name) to `sessionStorage`. Refreshing on checkout step 2 no longer loses all state and crashes the page.
- **`OrderContainer`** — saves draft to `sessionStorage` on every "Continue to Payment" click.
- **`CheckoutPaymentContainer`** — falls back to `sessionStorage` draft when router state is absent (e.g. after a page refresh). Re-validates the coupon via `/api/client/billing/coupons/validate` on mount; shows a warning and falls back to base price if the coupon has since expired.
- **`ServerBillingContainer`** — fetches all available billing cycles on mount. Shows a `BillingCycleBox` selector above the renewal button when more than one cycle is configured. Passes the selected cycle to both the free renewal handler and `PaymentContainer` (paid renewal).

---

## Files Changed

### New Files
| File | Purpose |
|---|---|
| `app/Http/Requests/Api/Client/Billing/UpdateCheckoutRequest.php` | FormRequest for updateIntent / updatePayment / updateOrder |
| `app/Services/Billing/BillingDefaults.php` | Single source of truth for default billing days |
| `app/Console/Commands/Billing/RefreshNodeAvailabilityCommand.php` | Proactive Wings availability cache refresh |
| `app/Models/Billing/PaymentTransaction.php` | Eloquent model for payment_transactions |
| `database/migrations/2026_05_25_000001_add_billing_indexes.php` | Indexes on renewal_date and orders lookup columns |
| `database/migrations/2026_05_25_000002_create_payment_transactions_table.php` | payment_transactions table |
| `database/migrations/2026_05_25_000003_migrate_orders_to_payment_transactions.php` | Data migration from orders to payment_transactions |
| `resources/scripts/components/billing/ProcessorSelectorGrid.tsx` | Shared processor selector card grid component |
| `resources/scripts/hooks/useCheckoutDraft.ts` | sessionStorage-backed checkout state persistence hook |

### Modified Files
- `app/Models/Billing/Order.php`
- `app/Services/Billing/BillingCycleService.php`
- `app/Services/Billing/BillingValidationService.php`
- `app/Services/Billing/CreateOrderService.php`
- `app/Services/Billing/CreateServerService.php`
- `app/Services/Billing/NodeAvailabilityService.php`
- `app/Services/Billing/OrderProcessorService.php`
- `app/Services/Billing/PlanChangeService.php`
- `app/Services/Billing/ServerFulfillmentService.php`
- `app/Services/Billing/ServerRenewalService.php`
- `app/Console/Kernel.php`
- `app/Http/Controllers/Api/Application/Billing/BillingController.php`
- `app/Http/Controllers/Api/Client/Billing/CheckoutController.php`
- `app/Http/Controllers/Api/Client/Billing/MollieCheckoutController.php`
- `app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php`
- `app/Http/Controllers/Api/Client/Billing/PlanChangeController.php`
- `resources/scripts/components/account/billing/order/CheckoutPaymentContainer.tsx`
- `resources/scripts/components/account/billing/order/OrderContainer.tsx`
- `resources/scripts/components/account/billing/order/PaymentMethodSelector.tsx`
- `resources/scripts/components/server/billing/PaymentContainer.tsx`
- `resources/scripts/components/server/billing/ServerBillingContainer.tsx`

---

## Migration Notes

- Run `php artisan migrate` — three new migrations will execute.
- The `payment_transactions` data migration runs in a transaction. Verify row counts before proceeding to the column-drop phase (see `docs/billing-refactor/phase-5-data-model.md` for the dual-write rollout checklist).
- Old processor-specific columns on `orders` are intentionally retained for this release. They will be dropped in a follow-up after one release cycle with no issues.
