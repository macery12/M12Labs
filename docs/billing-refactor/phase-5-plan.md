# Phase 5 — Separating Payments from Orders

> **Goal**: `payment_transactions` becomes the authoritative source for all processor-specific
> payment data. `orders` keeps only what is needed for fulfillment, display, and financial summaries.
>
> - `payment_processor` stays on `orders` — it is the free-order marker and a quick lookup shortcut.
> - Financial summary columns (`total`, `subtotal`, `discount`, `final_price`) stay on `orders`.
> - Everything else processor-specific moves to (or is already in) `payment_transactions`.

---

## Final table shapes

### `orders` (after cleanup)

| Column | Notes |
|---|---|
| `id` | PK — display as `#ORD-00123` in UI |
| `name` | User-defined server name |
| `user_id` | FK → users |
| `description` | |
| `status` | `pending` / `processed` / `failed` / `expired` |
| `type` | `new` / `upg` / `ren` |
| `product_id` | FK → products |
| `egg_id` | FK → eggs (nullable) |
| `node_id` | nullable |
| `server_id` | FK → servers (nullable) |
| `billing_days` | |
| `subtotal` / `discount` / `total` / `final_price` | Financial summary — kept for quick access |
| `multiplier_used` / `node_multiplier_used` | nullable |
| `coupon_id` | FK → coupons (nullable) |
| `variables` | JSON (nullable) |
| `domain_payload` | JSON (nullable) |
| `threat_index` | |
| `payment_processor` | `stripe` / `mollie` / `paypal` / `free` — **kept** |
| `created_at` / `updated_at` | |

### `payment_transactions` (no schema changes needed)

| Column | Notes |
|---|---|
| `id` | PK |
| `order_id` | FK → orders (cascade delete) |
| `processor` | `stripe` / `mollie` / `paypal` |
| `external_id` | Stripe `pi_*`, Mollie `tr_*`, PayPal order ID |
| `capture_id` | Stripe charge ID / PayPal capture ID |
| `status` | Processor-specific status string |
| `amount` / `currency` | |
| `payer_id` / `payer_email` | PayPal-specific |
| `payment_token` | Mollie / PayPal redirect token |
| `raw_metadata` | JSON catch-all |
| `captured_at` | |
| `created_at` / `updated_at` | |

### Columns being dropped from `orders`

| Column | Where the data lives now |
|---|---|
| `payment_intent_id` | `payment_transactions.external_id` (processor=stripe) |
| `payment_token` | `payment_transactions.payment_token` |
| `mollie_payment_id` | `payment_transactions.external_id` (processor=mollie) |
| `paypal_order_id` | `payment_transactions.external_id` (processor=paypal) |
| `paypal_capture_id` | `payment_transactions.capture_id` |
| `paypal_payer_id` | `payment_transactions.payer_id` |
| `paypal_payer_email` | `payment_transactions.payer_email` |
| `paypal_status` | `payment_transactions.status` |
| `paypal_amount` | `payment_transactions.amount` |
| `paypal_currency` | `payment_transactions.currency` |
| `paypal_captured_at` | `payment_transactions.captured_at` |

---

## Step 1 — Dual-write ✅ Complete

All three checkout controllers write to `payment_transactions` on every order creation.
Capture/webhook handlers update the transaction record with final status, capture ID, and amounts.
The old `orders` columns are still written to in parallel — nothing is broken yet.

---

## Step 2 — Run pending migration ✅ Complete

**`2026_05_27_000002_make_payment_intent_id_nullable_on_orders`** must be applied before any
other changes go to production.

What it does:
1. Nulls out all `free-%` placeholder values in `payment_intent_id` — covers both fully-free orders
   and 100%-coupon stripe/paypal orders where the fake value was written anyway.
2. Alters `payment_intent_id` to `NULLABLE`.

Required because `CreateOrderService` now stores `null` instead of a synthetic `free-*` string.

---

## Step 3 — Flip controller lookup queries ⏳

Every place that looks up an order by a processor-specific identifier must query
`payment_transactions` instead of `orders`. There are **14 sites** across 5 files.

### `CheckoutController` — 1 site

**`processPaid()`**
```php
// Before
$order = Order::where('payment_intent_id', $intentId)
    ->where('user_id', $user->id)
    ->firstOrFail();

// After
$transaction = PaymentTransaction::where('processor', 'stripe')
    ->where('external_id', $intentId)
    ->firstOrFail();
$order = $transaction->order;
abort_if($order->user_id !== $user->id, 403);
```

### `MollieCheckoutController` — 5 sites

**`redirectToCheckout()` and `updatePayment()`** (user-scoped lookups)
```php
// Before
$order = Order::where('mollie_payment_id', $paymentId)
    ->where('user_id', $user->id)
    ->firstOrFail();

// After
$transaction = PaymentTransaction::where('processor', 'mollie')
    ->where('external_id', $paymentId)
    ->firstOrFail();
$order = $transaction->order;
abort_if($order->user_id !== $user->id, 403);
```

**`processPayment()` webhook**
```php
// Before
$order = Order::where('mollie_payment_id', $paymentId)->firstOrFail();

// After
$transaction = PaymentTransaction::where('processor', 'mollie')
    ->where('external_id', $paymentId)
    ->firstOrFail();
$order = $transaction->order;
```

**Status-check / latest lookup**
```php
// Before
$order = Order::where('mollie_payment_id', $paymentId)->latest()->first();

// After
$transaction = PaymentTransaction::where('processor', 'mollie')
    ->where('external_id', $paymentId)
    ->latest()
    ->first();
$order = $transaction?->order;
```

**Token-based lookup (cancel/return flow)**
```php
// Before
$order = Order::where('payment_token', $token)
    ->where('payment_processor', 'mollie')
    ->firstOrFail();

// After
$transaction = PaymentTransaction::where('processor', 'mollie')
    ->where('payment_token', $token)
    ->firstOrFail();
$order = $transaction->order;
```

### `MollieWebhookVerificationService` — 1 site

```php
// Before
$order = Order::where('payment_token', $token)
    ->where('mollie_payment_id', $paymentId)
    ->latest()
    ->first();

// After
$transaction = PaymentTransaction::where('processor', 'mollie')
    ->where('payment_token', $token)
    ->where('external_id', $paymentId)
    ->latest()
    ->first();
$order = $transaction?->order;
```

### `PayPalCheckoutController` — 6 sites

**`captureOrder()`, `redirectToApproval()`, `updateOrder()`, `checkOrderStatus()`** (user-scoped)
```php
// Before
$order = Order::where('paypal_order_id', $orderId)
    ->where('user_id', $user->id)
    ->firstOrFail();

// After
$transaction = PaymentTransaction::where('processor', 'paypal')
    ->where('external_id', $orderId)
    ->firstOrFail();
$order = $transaction->order;
abort_if($order->user_id !== $user->id, 403);
```

**`processPayment()` webhook / latest lookup**
```php
// Before
$order = Order::where('paypal_order_id', $paypalOrderId)->latest()->first();

// After
$transaction = PaymentTransaction::where('processor', 'paypal')
    ->where('external_id', $paypalOrderId)
    ->latest()
    ->first();
$order = $transaction?->order;
```

**`getOrderFromToken()`**
```php
// Before
$order = Order::where('payment_token', $token)
    ->where('user_id', $user->id)
    ->where('payment_processor', 'paypal')
    ->firstOrFail();

// After
$transaction = PaymentTransaction::where('processor', 'paypal')
    ->where('payment_token', $token)
    ->firstOrFail();
$order = $transaction->order;
abort_if($order->user_id !== $user->id, 403);
```

### `PayPalWebhookController` — 1 site

```php
// Before
$order = Order::where('paypal_order_id', $paypalOrderId)->latest()->first();

// After
$transaction = PaymentTransaction::where('processor', 'paypal')
    ->where('external_id', $paypalOrderId)
    ->latest()
    ->first();
$order = $transaction?->order;
```

---

## Step 4 — Update OrderTransformers ⏳

Both `app/Transformers/Api/Application/OrderTransformer.php` and
`app/Transformers/Api/Client/OrderTransformer.php` need the same changes.

### Replace the scattered processor columns with a `transaction` block

```php
// Before — individual columns read off $model
'payment_intent_id'  => $model->payment_intent_id,
'mollie_payment_id'  => $model->mollie_payment_id,
'paypal_order_id'    => $model->paypal_order_id,
'paypal_capture_id'  => $model->paypal_capture_id,
'paypal_payer_id'    => $model->paypal_payer_id,
'paypal_payer_email' => $model->paypal_payer_email,
'paypal_status'      => $model->paypal_status,
'paypal_amount'      => $model->paypal_amount,
'paypal_currency'    => $model->paypal_currency,
'paypal_captured_at' => $model->paypal_captured_at?->toIso8601String(),

// After — single transaction block
'transaction' => $model->transaction ? [
    'external_id' => $model->transaction->external_id,
    'capture_id'  => $model->transaction->capture_id,
    'status'      => $model->transaction->status,
    'amount'      => $model->transaction->amount,
    'currency'    => $model->transaction->currency,
    'payer_id'    => $model->transaction->payer_id,
    'payer_email' => $model->transaction->payer_email,
    'captured_at' => $model->transaction->captured_at?->toIso8601String(),
] : null,
```

### Simplify `resolvePaymentProcessor()`

`payment_processor` stays on `orders` and is always correct, so the `free-*` inference logic is no
longer needed:

```php
// Before — inferred free from payment_intent_id pattern + zero total
private function resolvePaymentProcessor(Order $model): string
{
    $processor = strtolower((string) ($model->payment_processor ?? ''));
    if ($processor === '') { $processor = 'stripe'; }
    if ($processor === 'stripe') {
        $hasExternalPaymentReference = !empty($model->mollie_payment_id)
            || !empty($model->paypal_order_id)
            || !empty($model->paypal_capture_id);
        $isFreeLikeIntent = str_starts_with((string) ($model->payment_intent_id ?? ''), 'free-');
        $isZeroTotal = (float) $model->total <= self::FREE_ORDER_EPSILON;
        if ($isZeroTotal && !$hasExternalPaymentReference && $isFreeLikeIntent) {
            return 'free';
        }
    }
    return $processor;
}

// After — payment_processor is always the correct value
private function resolvePaymentProcessor(Order $model): string
{
    return strtolower((string) ($model->payment_processor ?? 'stripe'));
}
```

### Eager-load requirement

Add `transaction` wherever `Order` collections are fetched to avoid N+1:
```php
Order::with('transaction')->...
```

---

## Step 5 — Drop the 11 legacy columns 🔒

> **Do not run until Steps 2–4 have been live in production for at least one release cycle.**

### Migration

```php
Schema::table('orders', function (Blueprint $table) {
    $table->dropColumn([
        'payment_intent_id',
        'payment_token',
        'mollie_payment_id',
        'paypal_order_id',
        'paypal_capture_id',
        'paypal_payer_id',
        'paypal_payer_email',
        'paypal_status',
        'paypal_amount',
        'paypal_currency',
        'paypal_captured_at',
    ]);
});
```

### `Order` model cleanup (same deploy as migration)

- Remove the 11 columns from `$fillable`, `$casts`, and validation rules
- Remove their `@property` phpdoc entries
- In `CreateOrderService` and the checkout controllers: remove the dual-write lines that still
  set these fields on `$order`

### Pre-flight checklist

- [ ] Migration `2026_05_27_000002` has been run (Step 2)
- [ ] All 14 lookup sites use `PaymentTransaction` queries (Step 3 verified in staging)
- [ ] Both `OrderTransformer` files updated and API consumers read from `transaction` block (Step 4)
- [ ] Zero read usages confirmed:
      `grep -r 'payment_intent_id\|mollie_payment_id\|paypal_order_id\|paypal_capture_id\|payment_token\|paypal_payer' app/`
- [ ] `ServerFulfillmentService::buildMetadata()` uses `$order->transaction->external_id`
- [ ] Database backup taken immediately before running

### Rollback

Columns can be re-added in a new migration; `payment_transactions` retains all data for re-backfill.

---

## Summary

| Step | What | State |
|---|---|---|
| 1 | Dual-write to `payment_transactions` | ✅ Done |
| 2 | Run `payment_intent_id` nullable migration | ⏳ Pending |
| 3 | Flip all 14 lookup sites to query `payment_transactions` | ⏳ Pending |
| 4 | Consolidate transformer output into `transaction` block | ⏳ Pending |
| 5 | Drop 11 legacy columns from `orders` | 🔒 After Steps 2–4 in prod |
