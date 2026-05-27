# Phase 2 — Controller Deduplication

**Risk:** Medium  
**Effort:** Medium (half a day to a day)  
**Depends on:** Phase 0 (dead injections removed first makes this cleaner)  
**Blocks:** Phase 3 (service restructure)

---

## Goals

Eliminate the three-way duplication of `getOrderType()` and the two-way duplication of
`dispatchPaymentFailedEmail()`. Introduce FormRequest classes for the two most complex
checkout endpoints so that request validation is not scattered inline across three controllers.

---

## Background: Duplicated Methods

### `getOrderType()` — in three places
```php
// Identical in CheckoutController, MollieCheckoutController, PayPalCheckoutController
private function getOrderType(Request $request): string
{
    if ($request->has('renewal') && $request->boolean('renewal')) {
        return Order::TYPE_REN;
    }
    return Order::TYPE_NEW;
}
```

### `dispatchPaymentFailedEmail()` — in two places
```php
// Nearly identical in MollieCheckoutController and PayPalCheckoutController
private function dispatchPaymentFailedEmail(Order $order, string $reason, string $processor): void
{ ... event(new PaymentFailed(...)) ... }
```

---

## Tasks

### 2.1 — Extract `getOrderType()` to `Order` model as a static factory method

`Order` is the right home for a method that resolves a value from an HTTP request into
one of its own constants.

```php
// app/Models/Billing/Order.php
public static function resolveTypeFromRequest(Request $request): string
{
    return ($request->has('renewal') && $request->boolean('renewal'))
        ? self::TYPE_REN
        : self::TYPE_NEW;
}
```

**Update all three controllers** to call `Order::resolveTypeFromRequest($request)` and
delete their private `getOrderType()` methods.

---

### 2.2 — Extract `dispatchPaymentFailedEmail()` to `ServerFulfillmentService`

`ServerFulfillmentService` already has `dispatchPaymentReceivedEmail()`. Adding a parallel
`dispatchPaymentFailedEmail()` there gives both Mollie and PayPal a single call site and
keeps all billing notification dispatch in one service.

```php
// app/Services/Billing/ServerFulfillmentService.php
public function dispatchPaymentFailedEmail(Order $order, string $reason, string $processor): void
{
    // move body from MollieCheckoutController here verbatim
}
```

**Update callers:**
- `MollieCheckoutController::processPayment()` — 3 call sites
- `PayPalCheckoutController::captureOrder()` — 1 call site

**Delete** the private method from both controllers.

---

### 2.3 — Introduce `UpdateCheckoutRequest` FormRequest

All three `update*` methods (`updateIntent`, `updatePayment`, `updateOrder`) do the same
manual field extraction and validation inline:

```php
$serverName  = trim((string) $request->input('name', ''));
$nodeId      = (int) $request->input('node_id');
$billingDays = (int) ($request->input('billing_days') ?? 30);
$couponId    = $request->input('coupon_id') ? (int) $request->input('coupon_id') : null;
$eggId       = $request->input('egg_id') ? (int) $request->input('egg_id') : null;
$isRenewal   = $request->has('renewal') && $request->boolean('renewal');
// ... etc
```

**Create:** `app/Http/Requests/Api/Client/Billing/UpdateCheckoutRequest.php`

```php
class UpdateCheckoutRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'name'           => ['nullable', 'string', 'min:3', 'max:191'],
            'node_id'        => ['nullable', 'integer', 'exists:nodes,id'],
            'egg_id'         => ['nullable', 'integer', 'exists:eggs,id'],
            'billing_days'   => ['nullable', 'integer', 'min:1'],
            'coupon_id'      => ['nullable', 'integer', 'exists:coupons,id'],
            'renewal'        => ['nullable', 'boolean'],
            'server_id'      => ['nullable', 'integer', 'exists:servers,id'],
            'variables'      => ['nullable', 'array'],
            'domain_payload' => ['nullable', 'array'],
        ];
    }

    public function isRenewal(): bool        { return $this->boolean('renewal', false); }
    public function serverName(): string     { return trim($this->string('name', '')); }
    public function nodeId(): int            { return (int) $this->input('node_id', 0); }
    public function billingDays(): int       { return (int) $this->input('billing_days', 30); }
    public function couponId(): ?int         { return $this->input('coupon_id') ? (int)$this->input('coupon_id') : null; }
    public function eggId(): ?int            { return $this->input('egg_id') ? (int)$this->input('egg_id') : null; }
    public function serverId(): ?int         { return $this->input('server_id') ? (int)$this->input('server_id') : null; }
}
```

**Update** `updateIntent`, `updatePayment`, and `updateOrder` method signatures to accept
`UpdateCheckoutRequest` instead of `Request` and replace all the manual `$request->input()`
calls with the typed accessor methods.

**Note:** The `billingDays` default of `30` in the FormRequest accessor is intentional
for backwards compatibility. The right long-term fix (default pulled from settings) is
tracked in the settings-config cleanup below.

---

### 2.4 — Replace hardcoded `30` default with settings-aware constant

**Problem:** The default billing cycle of `30` days is hardcoded in ~10 places:
- `UpdateCheckoutRequest` (just created above)
- `CheckoutController::renewFree()`
- `MollieCheckoutController::updatePayment()`
- `PayPalCheckoutController::updateOrder()`
- `OrderProcessorService::createServerOrder()` parameter default
- `OrderProcessorService::processRenewal()` parameter default
- `ServerRenewalService::renew()` fallback
- `BillingCycleService::getAvailableCycles()` fallback

**Fix:** Create a small helper that reads the setting once:

```php
// app/Services/Billing/BillingDefaults.php
class BillingDefaults
{
    public static function defaultBillingDays(): int
    {
        return (int) Setting::get(
            'settings::modules:billing:renewal:default_billing_days', 30
        );
    }
}
```

Replace all hardcoded `30` defaults in the billing layer with
`BillingDefaults::defaultBillingDays()`. This is a pure rename/extract — no behavior change.

---

## Acceptance Criteria

- [ ] `getOrderType()` private method deleted from all three controllers.
- [ ] `Order::resolveTypeFromRequest()` static method exists and is used in all three controllers.
- [ ] `dispatchPaymentFailedEmail()` deleted from both controllers; now a public method on `ServerFulfillmentService`.
- [ ] `UpdateCheckoutRequest` FormRequest created with typed accessors.
- [ ] `updateIntent`, `updatePayment`, `updateOrder` all use the FormRequest.
- [ ] `BillingDefaults::defaultBillingDays()` replaces all hardcoded `30` defaults.
- [ ] No behavioral change — existing integration behavior unchanged.
- [ ] All existing tests pass.
