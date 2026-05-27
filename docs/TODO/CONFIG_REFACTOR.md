# Config Refactor Plan

> **Status**: Planning only — do NOT implement until all payment/billing flows are stable.
> Each config change must be reviewed carefully because values are also stored in the database
> via the `Settings` model (`Setting::get()`), and those DB values take precedence at runtime.

---

## Problem

Configs are spread across several files with no consistent ownership model:

| File | Purpose | Issue |
|---|---|---|
| `config/cashier.php` | Cashier/Stripe (now removed) | Was Cashier's config, contained our only copy of `STRIPE_WEBHOOK_SECRET` |
| `config/modules/billing.php` | Billing module feature flags + some Stripe keys | Stripe keys duplicated here AND read via `Setting::get()` at runtime — three sources of truth |
| `config/services.php` | Third-party credentials | Almost empty — only SES. Stripe, PayPal, Mollie credentials live elsewhere |
| `config/everest.php` | Panel-level settings | Fine as-is; panel-scoped settings belong here |
| `config/email.php` | Email domain blacklist | Tiny file — could live inside `everest.php` |
| `config/modules/` (6 files) | Feature module toggles | Inconsistent — some are env-only, others read DB settings at boot |

---

## Goal

**One place per concern.** Third-party API credentials → `config/services.php`.
Feature flags / module behaviour → `config/modules/*.php`. Panel settings → `config/everest.php`.

---

## Proposed Changes

### 1. `config/services.php` — become the home for all external API credentials

Move all third-party API keys and webhook secrets here.
Laravel's convention (`services.stripe`, `services.paypal`) is understood by many packages and devs.

```php
'stripe' => [
    // Public/secret keys are intentionally left blank here.
    // At runtime the app reads them from the database via Setting::get().
    // These env vars serve as fallback for fresh installs before the admin
    // has saved keys via the UI.
    'key'               => env('STRIPE_KEY', ''),
    'secret'            => env('STRIPE_SECRET', ''),
    'webhook_secret'    => env('STRIPE_WEBHOOK_SECRET'),
    'webhook_tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
],

'paypal' => [
    // Standalone PayPal REST API credentials.
    // Runtime values come from Setting::get(); these are env fallbacks.
    'client_id'     => env('PAYPAL_CLIENT_ID', ''),
    'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
    'mode'          => env('PAYPAL_MODE', 'sandbox'), // 'sandbox' or 'live'
    'webhook_id'    => env('PAYPAL_WEBHOOK_ID', ''),
],

'ses' => [ ... ], // keep existing
```

**Why this is better**: one file to hand to a new developer for "where are our API credentials".

### 2. `config/modules/billing.php` — strip credentials, keep behaviour

Remove the `keys` block (Stripe publishable/secret) and `paypal_standalone` credential block.
Those move to `services.php`. Keep only behaviour/feature-flag keys:

```php
'enabled'               => env('BILLING_ENABLED', false),
'processor'             => env('BILLING_PROCESSOR', 'stripe'),  // deprecated flag, keep for compat
'donations_enabled'     => env('BILLING_DONATIONS_ENABLED', false),
'paypal'                => env('BILLING_PAYPAL', false),        // PayPal via Stripe payment methods
'link'                  => env('BILLING_LINK', false),
'currency'              => [ 'symbol' => '$', 'code' => 'usd' ],
'links'                 => [ 'terms' => '', 'privacy' => '' ],
'renewal'               => [ ... ],  // unchanged
```

All callers currently reading `config('modules.billing.keys.secret')` should be updated to
`config('services.stripe.secret')` (with the DB `Setting::get()` taking precedence as today).

### 3. `config/cashier.php` — DELETE (done, see Cashier removal PR)

Already removed as part of the Cashier package removal.
The only value that mattered (`STRIPE_WEBHOOK_SECRET`) moved to `services.stripe.webhook_secret`.

### 4. `config/email.php` — absorb into `config/everest.php`

The file is 8 lines. Merge into `everest.php` under an `email` key:

```php
'email' => [
    'domain_blacklist' => [ 'test.com', 'website.com', 'example.com' ],
],
```

Then delete `config/email.php` and update the one or two callers.

### 5. `config/modules/` — add clear comments distinguishing env-only vs DB-backed

Each key should be annotated:

```php
// ENV ONLY — not overridden by DB settings
'enabled' => env('BILLING_ENABLED', false),

// DB BACKED — runtime value comes from Setting::get('settings::modules:billing:...')
// The value here is only used before the admin saves via the UI.
'keys' => [ ... ],
```

---

## Call-site Impact

When `config/modules/billing.php` keys are removed and moved to `services.php`, these files
need updating (search for `config('modules.billing.keys')`):

- `app/Services/Billing/StripeCustomerService.php`
- `app/Http/Controllers/Api/Client/Billing/CheckoutController.php`
- Any other service that calls `Setting::get('settings::modules:billing:keys:secret', config('modules.billing.keys.secret'))`

The pattern `Setting::get($key, config($fallback))` is the right pattern to keep —
just update the fallback from `config('modules.billing.keys.secret')` to `config('services.stripe.secret')`.

---

## What NOT to change

- `Setting::get()` as the primary runtime source for billing keys — this is correct and must stay.
- `config/modules/billing.php` renewal/suspension thresholds — fine where they are.
- `config/everest.php` panel settings — well-structured, leave alone.
- `config/auth.php`, `config/sanctum.php`, `config/logging.php` — standard Laravel files, leave alone.

---

## Suggested Implementation Order

1. Add `stripe` and `paypal` blocks to `config/services.php`
2. Update call-sites in billing services to use `config('services.stripe.secret')` as fallback
3. Remove credential blocks from `config/modules/billing.php`
4. Merge `config/email.php` into `config/everest.php`
5. Update callers of `config('email.domain_blacklist')` → `config('everest.email.domain_blacklist')`
6. Add env-only vs DB-backed comments to `config/modules/billing.php`
7. Test all billing flows end-to-end before deploying
