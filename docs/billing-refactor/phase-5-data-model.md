# Phase 5 — Data Model: Payment Transactions Table

**Risk:** High  
**Effort:** Large (1–2 days plus migration testing)  
**Depends on:** Phase 3 (services consolidated before touching the data layer)  
**Blocks:** Nothing downstream, but enables clean Phase 6 frontend simplifications

---

## Goals

Move all processor-specific columns off the `orders` table and into a new
`payment_transactions` table that is polymorphic by processor. Fix the overloaded
`payment_intent_id` column. This is a structural improvement that prevents the `orders`
table from growing another set of columns every time a new payment processor is added.

This is the most invasive database change in the entire refactor. **Run all migrations
in a staging environment first.**

---

## Background: What's Wrong With the Current `orders` Table

The `orders` table currently stores:

| Column group | Belongs to |
|---|---|
| `payment_intent_id` | Stripe (but also used as a synthetic ID for free orders) |
| `payment_token` | Mollie & PayPal (used to reconnect redirect callbacks) |
| `mollie_payment_id` | Mollie |
| `paypal_order_id` | PayPal |
| `paypal_capture_id` | PayPal |
| `paypal_payer_id` | PayPal |
| `paypal_payer_email` | PayPal |
| `paypal_status` | PayPal |
| `paypal_amount` | PayPal |
| `paypal_currency` | PayPal |
| `paypal_captured_at` | PayPal |

Adding a 4th processor means another migration with 4–8 more columns. The `payment_intent_id`
unique constraint is also awkward because free orders use `"free-{uuid}"` as a synthetic value.

---

## Tasks

### 5.1 — Create the `payment_transactions` table

```php
Schema::create('payment_transactions', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('order_id')->index();
    $table->string('processor');       // 'stripe', 'mollie', 'paypal', 'free'
    $table->string('external_id')->nullable();    // Stripe intent ID, Mollie payment ID, PayPal order ID
    $table->string('capture_id')->nullable();     // PayPal capture_id, Stripe charge ID
    $table->string('status')->nullable();         // processor-specific status string
    $table->decimal('amount', 10, 2)->nullable(); // amount in the processor's currency
    $table->string('currency', 10)->nullable();
    $table->string('payer_id')->nullable();       // PayPal payer_id, Stripe customer_id
    $table->string('payer_email')->nullable();
    $table->string('payment_token')->nullable();  // Mollie/PayPal redirect token
    $table->json('raw_metadata')->nullable();     // catch-all for processor-specific data
    $table->timestamp('captured_at')->nullable();
    $table->timestamps();

    $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
    $table->index(['processor', 'external_id']);
    $table->index('payment_token');
});
```

**Note on `raw_metadata`:** This JSON column absorbs any processor-specific fields that
don't have a first-class column. It avoids requiring another migration every time a
processor adds a new response field.

---

### 5.2 — Migrate existing data

Write a data migration to populate `payment_transactions` from current `orders` rows:

```php
// For Stripe orders:
INSERT INTO payment_transactions (order_id, processor, external_id, status, created_at, updated_at)
SELECT id, 'stripe', payment_intent_id, status, created_at, updated_at
FROM orders
WHERE payment_processor = 'stripe'

// For Mollie orders:
INSERT INTO payment_transactions (order_id, processor, external_id, payment_token, ...)
SELECT id, 'mollie', mollie_payment_id, payment_token, ...
FROM orders
WHERE payment_processor = 'mollie'

// For PayPal orders:
INSERT INTO payment_transactions (order_id, processor, external_id, capture_id, status, amount,
    currency, payer_id, payer_email, payment_token, captured_at, ...)
SELECT id, 'paypal', paypal_order_id, paypal_capture_id, paypal_status, paypal_amount,
    paypal_currency, paypal_payer_id, paypal_payer_email, payment_token, paypal_captured_at, ...
FROM orders
WHERE payment_processor = 'paypal'
```

Run this in a transaction. Verify row counts match before proceeding.

---

### 5.3 — Add `PaymentTransaction` model and `Order::transaction()` relationship

```php
// app/Models/Billing/PaymentTransaction.php
class PaymentTransaction extends Model
{
    protected $table = 'payment_transactions';
    protected $fillable = [...];
    protected $casts = ['captured_at' => 'datetime', 'raw_metadata' => 'array'];

    public function order() { return $this->belongsTo(Order::class); }
}
```

```php
// app/Models/Billing/Order.php
public function transaction(): HasOne
{
    return $this->hasOne(PaymentTransaction::class);
}
```

---

### 5.4 — Update service layer to write to `payment_transactions`

Update each controller/service that currently writes processor-specific columns:

**`CheckoutController`** (Stripe): Instead of storing `payment_intent_id` on the order,
create a `PaymentTransaction` record with `processor = 'stripe'`, `external_id = $intentId`.

**`MollieCheckoutController`**: Store `mollie_payment_id` and `payment_token` on
`PaymentTransaction` instead of `Order`.

**`PayPalCheckoutController`**: Store all `paypal_*` columns on `PaymentTransaction`.

**`ServerFulfillmentService::buildMetadata()`**: Update to look up transaction via
`$order->transaction` instead of reading columns directly off `$order`.

All lookup queries like `Order::where('mollie_payment_id', $paymentId)` become:
```php
Order::whereHas('transaction', fn($q) => $q->where('external_id', $paymentId)->where('processor', 'mollie'))
```

Or more efficiently:
```php
$transaction = PaymentTransaction::where('external_id', $paymentId)->where('processor', 'mollie')->firstOrFail();
$order = $transaction->order;
```

---

### 5.5 — Fix `payment_intent_id` overloading for free orders

Currently free orders store `"free-{uuid}"` in `payment_intent_id` to satisfy the unique
constraint. With `PaymentTransaction` in place, `payment_intent_id` on the `orders` table
can be nullable with no unique constraint. Free orders simply don't create a `payment_transactions`
row (or create one with `processor = 'free'` for audit trail).

Migration step: Remove the unique constraint on `orders.payment_intent_id` and make it nullable.

---

### 5.6 — (Optional, Post-Validation) Drop old columns from `orders`

Only after all code has been updated and verified in production:

```php
Schema::table('orders', function (Blueprint $table) {
    $table->dropColumn([
        'payment_intent_id', 'payment_token',
        'mollie_payment_id',
        'paypal_order_id', 'paypal_capture_id', 'paypal_payer_id',
        'paypal_payer_email', 'paypal_status', 'paypal_amount',
        'paypal_currency', 'paypal_captured_at',
    ]);
});
```

Keep both old and new columns live in production for at least one release cycle to allow
safe rollback. Drop columns only after confirming no code path reads the old columns.

---

## Migration Safety Checklist

- [ ] Full database backup before running.
- [ ] Test in staging with production data snapshot.
- [ ] Run data migration in a transaction; verify row counts before committing.
- [ ] Deploy code reading from `payment_transactions` first (reads old columns as fallback).
- [ ] Deploy code writing to `payment_transactions` (writes both old and new in parallel).
- [ ] After one release cycle with no issues, stop writing to old columns.
- [ ] After another release cycle, drop old columns.

---

## Acceptance Criteria

- [ ] `payment_transactions` table exists with index on `(processor, external_id)`.
- [ ] `PaymentTransaction` model with `order()` relationship.
- [ ] `Order::transaction()` `HasOne` relationship.
- [ ] All webhook handlers look up orders via `PaymentTransaction`.
- [ ] Free orders do not write to `payment_intent_id`.
- [ ] Old `paypal_*` and `mollie_*` columns still exist (not dropped yet).
- [ ] All checkout flows tested end-to-end in staging.
