# Laravel 13 Upgrade Guide

Migration from Laravel 12 to Laravel 13.

This is step 3 of the backend major-upgrade path.

---

## Targets

| Package | Target |
|---|---|
| `php` | `^8.3` |
| `laravel/framework` | `^13.0` |
| `phpunit/phpunit` | `^12.0` |
| `laravel/tinker` | `^3.0` |

Laravel 13 requires PHP 8.3+.

---

## Phase 1: Platform and dependency constraints

Update `composer.json`:

```json
{
  "require": {
    "php": "^8.3",
    "laravel/framework": "^13.0",
    "laravel/tinker": "^3.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^12.0"
  },
  "config": {
    "platform": {
      "php": "8.3.0"
    }
  }
}
```

Then run:

```bash
composer update
```

---

## Phase 2: High-impact code checks

1. CSRF middleware rename:
   - move direct references from `VerifyCsrfToken` / `ValidateCsrfToken` to `PreventRequestForgery` where applicable.

2. Cache hardening config:
   - review Laravel 13 `serializable_classes` cache behavior.
   - prefer arrays/scalars in cache payloads; if object caching is required, allow-list explicit classes.

3. Upsert behavior:
   - ensure all MySQL/MariaDB `upsert(...)` calls provide non-empty `uniqueBy` values.

4. Queue event changes:
   - `JobAttempted` now exposes `exception` instead of `exceptionOccurred`.
   - `QueueBusy` property name updates (`connection` -> `connectionName`).

5. Polyfill helper conflicts:
   - avoid legacy `array_first` / `array_last` helper ambiguity.
   - standardize on `Illuminate\Support\Arr::first` and `Arr::last`.

---

## Phase 3: Low-impact checks likely to appear in tests

- route precedence with domain routes
- pagination bootstrap view alias usage
- custom manager extend callback binding assumptions
- tests relying on persistent Str factories across test methods

---

## Phase 4: Verification

```bash
php artisan about
php artisan test
./vendor/bin/phpstan analyse
```

Run focused regression tests for:

- authentication and CSRF-protected forms
- queue listeners and monitoring hooks
- cache read/write paths

---

## Done criteria

- PHP constraint and platform set to 8.3 baseline
- `laravel/framework` at `^13.0`
- `phpunit/phpunit` at `^12.0`
- test suite and static analysis pass
