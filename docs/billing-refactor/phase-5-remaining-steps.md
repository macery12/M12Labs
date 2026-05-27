# Phase 5 — DB Migration Audit & Cleanup Plan

> Last updated: 2026-05-27  
> Answers locked in: (1) `2026_05_25_000002` already applied locally; (2) `payment_processor` comes  
> from `payment_transactions.processor` — the column will be dropped from `orders`; (3) order numbers  
> use the auto-increment `id` formatted as `#ORD-00123`, no uuid needed.

---

## Current migration status

| Migration file | Purpose | State |
|---|---|---|
| `2026_05_25_000002_create_payment_transactions_table` | Create the `payment_transactions` table | ✅ Applied locally |
| `2026_05_25_000003_migrate_orders_to_payment_transactions` | Copy existing processor data from `orders` into `payment_transactions` | ✅ Applied locally |
| `2026_05_26_000001_cleanup_free_order_payment_transactions` | Removed synthetic `free-*` rows | ✅ Applied — obsolete (source fixed in `000003`) |
| `2026_05_27_000001_add_uuid_to_orders_table` | Adds `uuid` column to `orders` | ⚠️ **Delete this file** — uuid is a future UI concern |
| `2026_05_27_000002_make_payment_intent_id_nullable_on_orders` | Nulls `free-{uuid}` values, makes column nullable | ⏳ **Must run** — required for free-order creation |

### Action: delete `2026_05_27_000001_add_uuid_to_orders_table.php`

The uuid column belongs to the future orders-UI refactor phase, not phase 5. Along with deleting the
migration file, revert these two code changes:

- **`CreateOrderService.php`** — remove `$order->uuid = (string) Str::uuid();` and the `Str` import
- **`Order.php`** — remove `uuid` from `$fillable` and the `@property string $uuid` phpdoc line

### Action: keep `2026_05_27_000002` as a standalone file (cannot consolidate — `000002` already applied)

This migration is required: `CreateOrderService` now stores `null` in `payment_intent_id` for free
orders. Without it, free order creation throws a NOT NULL constraint violation.

---

## Step 1 — Dual-write to `payment_transactions` ✅ Complete

All three checkout controllers (`CheckoutController`, `MollieCheckoutController`,
`PayPalCheckoutController`) now write to `payment_transactions` on every new order creation.
Capture/webhook handlers update the transaction record with final status, capture ID, and amounts.
The old `orders` columns are still written to in parallel — nothing is broken.

---

## Step 2 — Flip lookup queries from `orders` columns → `payment_transactions`

All webhook/callback/redirect controllers that look up an order by processor-specific identifiers need to
switch from querying the `orders` table directly to querying `payment_transactions` and loading the
related order through the `->order` relationship.

### Affected files and patterns

#### `MollieCheckoutController`

| Method | Old query | New query |
|---|---|---|
| `processPayment` (webhook) | `Order::where('mollie_payment_id', $paymentId)` | `PaymentTransaction::where('processor','mollie')->where('external_id',$paymentId)->firstOrFail()->order` |
| `redirectToCheckout` | `Order::where('mollie_payment_id', $paymentId)->where('user_id', $uid)` | `PaymentTransaction::where('processor','mollie')->where('external_id',$paymentId)->firstOrFail()->order` + verify `user_id` |
| `updatePayment` | `Order::where('mollie_payment_id', $paymentId)->where('user_id',...)->where('status', 'pending')` | same pattern via `PaymentTransaction` |

#### `PayPalCheckoutController`

| Method | Old query | New query |
|---|---|---|
| `captureOrder` | `Order::where('paypal_order_id', $orderId)->where('user_id',...)` | `PaymentTransaction::where('processor','paypal')->where('external_id',$orderId)->firstOrFail()->order` |
| `redirectToApproval` | `Order::where('paypal_order_id', $orderId)->where('user_id',...)` | same pattern |
| `updateOrder` | `Order::where('paypal_order_id', $orderId)->where('user_id',...)->where('status','pending')` | same pattern |
| `checkOrderStatus` | `Order::where('paypal_order_id', $orderId)->where('user_id',...)` | same pattern |
| `getOrderFromToken` | `Order::where('payment_token', $token)->where('user_id',...)->where('payment_processor','paypal')` | `PaymentTransaction::where('processor','paypal')->where('payment_token',$token)->firstOrFail()->order` |
| `processPayment` (webhook) | `Order::where('paypal_order_id', $paypalOrderId)` | `PaymentTransaction::where('processor','paypal')->where('external_id',$paypalOrderId)->firstOrFail()->order` |

#### `CheckoutController`

| Method | Old query | New query |
|---|---|---|
| `processPaid` | `Order::where('payment_intent_id', $intentId)->where('user_id',...)` | `PaymentTransaction::where('processor','stripe')->where('external_id',$intentId)->firstOrFail()->order` |

### Notes
- After this step, `mollie_payment_id`, `paypal_order_id`, `payment_intent_id`, `payment_token`, and
  `payment_processor` are **no longer read for lookup purposes** (still written to by dual-write until Step 5).
- Every `firstOrFail()` on `PaymentTransaction` automatically surfaces a 404 for missing records.
- Eager-load the relationship where needed: `PaymentTransaction::with('order')->...`
- Free orders have **no row** in `payment_transactions`. To detect a free order after Step 5 removes
  `payment_processor` from `orders`, use: `$order->transaction === null` (or
  `!$order->transaction()->exists()`). This is safe because paid orders always have a transaction row.

---

## Step 3 — Fix the free-order `payment_intent_id` hack ⏳ Migration pending

### What was done (code already changed)
`CreateOrderService` now stores `null` in `payment_intent_id` for free orders instead of the synthetic
`'free-' . substr(uuid_create(), 0, 16)` string. The `Order` validation rule was updated to
`nullable|string`.

### What still needs to happen (migration)
Run **`2026_05_27_000002_make_payment_intent_id_nullable_on_orders`** to:
1. Null out existing `free-{uuid}` values already in the database.
2. Alter the column to `NULLABLE` so the code change doesn't throw NOT NULL violations.

### Free-order detection going forward
Free orders have `payment_transactions` = null (no row). Replace any code that uses
`str_starts_with($order->payment_intent_id, 'free-')` or `$order->payment_processor === 'free'` with:
```php
!$order->transaction()->exists()   // lazy check
$order->transaction === null       // after eager-loading
```

---

## Step 4 — Update `OrderTransformer` to read from `payment_transactions`

Both transformers still read processor-specific IDs directly off the `Order` model:

| File | Old code | New code |
|---|---|---|
| `app/Transformers/Api/Application/OrderTransformer.php` | `$model->payment_intent_id` | `$model->transaction?->external_id` |
| `app/Transformers/Api/Application/OrderTransformer.php` | `$model->payment_processor` | `$model->transaction?->processor` |
| `app/Transformers/Api/Application/OrderTransformer.php` | `$model->mollie_payment_id` | *(remove — covered by `external_id`)* |
| `app/Transformers/Api/Application/OrderTransformer.php` | `$model->paypal_order_id` | *(remove — covered by `external_id`)* |
| `app/Transformers/Api/Client/OrderTransformer.php` | same as above | same as above |

### Free-order detection fix in transformers

Replace:
```php
str_starts_with($model->payment_intent_id, 'free-')
// or
$model->payment_processor === 'free'
```
With:
```php
$model->transaction === null
```
(requires the `transaction` relationship to be eager-loaded)

### Eager-load requirement
Wherever `Order` collections are returned (index endpoints, admin panels) ensure the `transaction`
relationship is eager-loaded to avoid N+1:
```php
Order::with('transaction')->...
```

---

## Step 5 — Drop legacy processor columns from `orders`

> **Do not run until Steps 1–4 have been live in production for at least one release cycle and you have
> confirmed that no code path reads from the columns being dropped.**

### Columns to drop

These 13 columns duplicate data now owned by `payment_transactions`:

```php
$table->dropColumn([
    'payment_intent_id',   // → payment_transactions.external_id (processor=stripe)
    'payment_processor',   // → payment_transactions.processor
    'payment_token',       // → payment_transactions.payment_token
    'mollie_payment_id',   // → payment_transactions.external_id (processor=mollie)
    'paypal_order_id',     // → payment_transactions.external_id (processor=paypal)
    'paypal_capture_id',   // → payment_transactions.capture_id
    'paypal_payer_id',     // → payment_transactions.payer_id
    'paypal_payer_email',  // → payment_transactions.payer_email
    'paypal_status',       // → payment_transactions.status
    'paypal_amount',       // → payment_transactions.amount
    'paypal_currency',     // → payment_transactions.currency
    'paypal_captured_at',  // → payment_transactions.captured_at
]);
```

### Clean `orders` table after the drop

```
orders
├── id                         (PK — this is the order number, formatted as #ORD-00123 in UI)
├── name                       user-defined server name
├── user_id                    FK → users
├── description
├── status                     pending / processed / failed / expired
├── type                       new / upg / ren
├── product_id                 FK → products
├── egg_id                     FK → eggs (nullable)
├── node_id                    (nullable)
├── server_id                  FK → servers (nullable)
├── billing_days
├── total / subtotal / discount / final_price
├── multiplier_used / node_multiplier_used
├── coupon_id                  FK → coupons (nullable)
├── variables                  JSON (nullable)
├── domain_payload             JSON (nullable)
├── threat_index
└── created_at / updated_at

payment_transactions  (linked via order_id FK)
├── id
├── order_id                   FK → orders (cascade delete)
├── processor                  stripe / mollie / paypal
├── external_id                Stripe pi_*, Mollie tr_*, PayPal order ID
├── capture_id                 Stripe charge ID / PayPal capture ID
├── status                     processor-specific status string
├── amount / currency
├── payer_id / payer_email
├── payment_token              Mollie / PayPal redirect token
├── raw_metadata               JSON catch-all
├── captured_at
└── created_at / updated_at
```

### `Order` model cleanup (run at same time as migration)
- Remove all 13 dropped columns from `$fillable`, `$casts`, and `$validationRules`
- Remove corresponding `@property` phpdoc entries
- `CreateOrderService`: remove the lines that set `$order->payment_processor`,
  `$order->mollie_payment_id`, `$order->paypal_order_id`, `$order->payment_token`

### Pre-flight checklist before running the migration
- [ ] All webhook/callback lookups use `payment_transactions` (Step 2 verified in staging)
- [ ] Free-order detection uses `$order->transaction === null` everywhere (Step 3 + 4)
- [ ] Both `OrderTransformer` files updated and tested (Step 4)
- [ ] Admin panel and API docs regenerated / no visible regressions
- [ ] Search confirms zero read usages: `grep -r 'mollie_payment_id\|paypal_order_id\|payment_intent_id\|paypal_capture_id\|payment_processor' app/` (excluding test fixtures)
- [ ] `ServerFulfillmentService::buildMetadata()` uses `$order->transaction->external_id`, not `$order->payment_intent_id`
- [ ] Database backup taken immediately before running

### Rollback plan
The columns can be re-added and `payment_transactions` still has all data, so a re-backfill migration
can restore them if needed.

---

## Future — Orders UI refactor (separate phase)

When the orders admin/client UI is built:

- **Order number display**: format the auto-increment `id` as `#ORD-{id padded to 5 digits}` in the
  frontend, e.g. `#ORD-00123`. No extra column needed.
- **Transaction link**: surface `order->transaction->external_id` as the "Transaction ID" with a
  deep-link to the payment processor's dashboard.
- **uuid (deferred)**: if non-guessable public URLs are needed (e.g. `/orders/abc-123`), add a `uuid`
  column to `orders` at that time alongside the UI code that uses it. Migration:
  ```php
  $table->uuid('uuid')->nullable()->unique()->after('id');
  // backfill:
  DB::table('orders')->whereNull('uuid')->chunkById(500, fn($rows) =>
      $rows->each(fn($r) => DB::table('orders')->where('id',$r->id)->update(['uuid' => Str::uuid()]))
  );
  ```

---

## Summary table

| Step | Description | State |
|---|---|---|
| 1 | Dual-write to `payment_transactions` | ✅ Done |
| 2 | Flip all lookups to use `payment_transactions` | ⏳ Not started |
| 3 | Make `payment_intent_id` nullable (code done, migration pending) | ⏳ Migration `000002` must run |
| 4 | Update transformers to use `transaction.external_id` / `transaction.processor` | ⏳ Not started |
| 5 | Drop legacy processor columns from `orders` | 🔒 Blocked by Steps 2–4 in prod |
| — | Orders UI refactor + optional uuid | 🔒 Future phase |
