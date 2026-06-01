# Laravel 12 Upgrade Guide

Migration from Laravel 11 to Laravel 12.

This file is step 2 of the backend major-upgrade path:

- Step 1: Laravel 10 -> 11 (`LARAVEL11_UPGRADE.md`)
- Step 2: Laravel 11 -> 12 (this file)
- Step 3: Laravel 12 -> 13 (`LARAVEL13_UPGRADE.md`)

---

## What Was Already Changed (simple, safe replacements)

These were updated directly in `composer.json`:

| Package | Before | Now |
|---|---:|---:|
| `laravel/framework` | `^11.0` | `^12.0` |
| `staudenmeir/belongs-to-through` | `^2.16.4` | `^2.17` |
| `phpunit/phpunit` | `~10.5.62` | `^11.5` |
| `nunomaduro/collision` | `^8.0` | `^8.9.4` |
| `larastan/larastan` | `^2.11` | `^3.10` |
| `phpstan/phpstan` | `^1.12` | `^2.2` |

Why these changed:

- `staudenmeir/belongs-to-through` v2.16.4 is pinned to `illuminate/database ^11.0`, which blocks Laravel 12.
- `nunomaduro/collision` older 8.x releases conflict with Laravel 12 and PHPUnit 11; newer 8.9.x supports both.
- `larastan/larastan` v2 only supports Illuminate up to 11.x; v3 is needed for 12.x.

---

## Targets

| Package | Target |
|---|---|
| `laravel/framework` | `^12.0` |
| `phpunit/phpunit` | `^11.5` |
| `larastan/larastan` | `^3.x` |
| `phpstan/phpstan` | `^2.x` |

Laravel 12 is a relatively low-friction upgrade compared to 10 -> 11.

---

## Phase 1: Install updated dependencies

Run as app user:

```bash
sudo -u www-data composer update laravel/framework phpunit/phpunit nunomaduro/collision staudenmeir/belongs-to-through larastan/larastan phpstan/phpstan --with-all-dependencies
```

If needed, run a full solve afterwards:

```bash
sudo -u www-data composer update
```

---

## Phase 2: Laravel 12 behavior checks

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

Current repo scan notes:

- No `HasUuids` usage found in `app/`.
- No `mergeIfMissing()` usage found in `app/`.
- `config/filesystems.php` already sets local root explicitly to `storage_path('app')`.
- No obvious `image` validation rules requiring SVG allowance were found in request classes.

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
- `composer.lock` resolved with compatible package graph
- tests and static analysis green
- no regressions in UUID/auth/storage/validation behavior
