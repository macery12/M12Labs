# Laravel 12 Upgrade Guide

Migration from Laravel 11 to Laravel 12.

This file is step 2 of the backend major-upgrade path:

- Step 1: Laravel 10 -> 11 (`LARAVEL11_UPGRADE.md`)
- Step 2: Laravel 11 -> 12 (this file)
- Step 3: Laravel 12 -> 13 (`LARAVEL13_UPGRADE.md`)

---

## Targets

| Package | Target |
|---|---|
| `laravel/framework` | `^12.0` |
| `phpunit/phpunit` | `^11.0` |

Laravel 12 is a relatively low-friction upgrade compared to 10 -> 11.

---

## Phase 1: Composer constraints

Update `composer.json`:

```json
{
  "require": {
    "laravel/framework": "^12.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.0"
  }
}
```

Then run:

```bash
composer update
```

---

## Phase 2: Breaking-change checks

Use official 12.x guide as source of truth, then verify app-specific items:

1. UUID behavior:
   - `HasUuids` now uses UUIDv7-compatible IDs.
   - If any models require previous ordered-v4 behavior, alias to `HasVersion4Uuids`.

2. Validation:
   - `image` rule excludes SVG by default.
   - Update any rules relying on SVG via `image:allow_svg` where intentional.

3. Storage local disk default:
   - If code depends on implicit `local` disk path (`storage/app`), ensure your `filesystems` config explicitly sets root.

4. Container nullable dependency behavior:
   - DI resolution now respects nullable class defaults.

5. Route precedence and request merge behavior:
   - Review any logic that relied on previous ordering or literal dot keys in `mergeIfMissing`.

---

## Phase 3: Verify and stabilize

```bash
php artisan about
php artisan test
./vendor/bin/phpstan analyse
```

Also run a focused smoke pass for:

- auth flows
- file upload and image validation paths
- storage reads/writes on `local` disk

---

## Done criteria

- `laravel/framework` at `^12.0`
- tests and static analysis green
- no regressions in UUID/auth/storage/validation behavior
