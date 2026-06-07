# Laravel 11 Upgrade Guide

> Status (reviewed 2026-05-31): this plan is still the correct first backend major step, but it is now part of a larger 10 -> 11 -> 12 -> 13 program.
>
> Canonical sequence:
> - `UPGRADE_ROADMAP_2026.md`
> - `LARAVEL11_UPGRADE.md` (this file)
> - `LARAVEL12_UPGRADE.md`
> - `LARAVEL13_UPGRADE.md`

Migration from Laravel 10 (`~10.48.29`) to Laravel 11.

> **This is a large, multi-day effort.** Do not attempt in a single PR. Follow the phased
> plan below and verify each phase independently before proceeding.

---

## Current state

| Package | Current | Target |
|---|---|---|
| `laravel/framework` | ~10.48.29 | `^11.0` |
| `laravel/sanctum` | ~3.2.1 | `^4.0` |
| `laravel/cashier` | `^15.4` | `^15.4` (L11 compatible, no change needed) |
| `laravel/socialite` | `^5.12` | `^5.15` (L11 compatible) |
| `laravel/tinker` | ~2.10.0 | `^2.10` (L11 compatible) |
| `laravel/helpers` | ~1.6.0 | `^1.7` (L11 compatible) |
| `laravel/ui` | ~4.2.1 | `^4.3` (L11 compatible) |
| `laravel/sail` | ~1.21.0 | `^1.26` (L11 compatible) |
| `laravel/cashier` | `^15.4` | `^15.4` (compatible with both L10 and L11) |
| `spatie/laravel-fractal` | ~6.0.3 | `^6.0` (L11 compatible) |
| `spatie/laravel-query-builder` | ~5.1.2 | `^6.0` (L11 compatible — **requires bump**) |
| `spatie/laravel-ignition` | `^2.0` | `^2.5` (L11 compatible) |
| `nunomaduro/collision` | ~7.0.5 | `^8.0` (L11 requires v8) |
| `nunomaduro/larastan` | ~2.4.1 | `^3.0` (L11 compatible — **requires bump**) |
| `phpstan/phpstan` | ~1.10.1 | `^2.0` (pairs with larastan 3) |
| `phpunit/phpunit` | ~10.5.x | `^11.0` (L11 default) |
| `symfony/*` | `~6.4.x` | `~7.2.x` (L11 uses Symfony 7) |
| `doctrine/dbal` | ~3.6.0 | `^4.0` (optional, L11 can use 3 or 4) |
| `dedoc/scramble` | `^0.8.6` | Check for L11 compatibility |
| PHP | `^8.1 \|\| ^8.2` | `^8.2` (L11 minimum) |

---

## Pre-flight checks

### PHP version

Laravel 11 requires **PHP 8.2+**. The production server is running PHP 8.3 — this is satisfied.
The `composer.json` must be updated:

```json
// Before
"php": "^8.1 || ^8.2"

// After
"php": "^8.2"
```

Also update the `config.platform.php` entry:

```json
// Before
"platform": { "php": "8.1.0" }

// After
"platform": { "php": "8.2.0" }
```

### Read the official upgrade guide

Work through the [official Laravel 10 → 11 upgrade guide](https://laravel.com/docs/11.x/upgrade)
line by line for the full list of breaking changes. This document covers the changes most likely
to affect *this specific codebase*.

---

## What changed in Laravel 11

### Application skeleton (high impact)

Laravel 11 ships with a **dramatically simplified application skeleton**. The default app
no longer includes:

- `app/Http/Kernel.php` — replaced by `bootstrap/app.php` fluent API
- `app/Http/Middleware/*.php` (most default middleware removed or inlined)
- `app/Providers/AuthServiceProvider.php`
- `app/Providers/BroadcastServiceProvider.php`
- `app/Providers/EventServiceProvider.php`
- `app/Providers/RouteServiceProvider.php`
- `config/auth.php`, `config/broadcasting.php`, `config/cors.php`, `config/hashing.php`,
  `config/logging.php`, `config/mail.php`, `config/queue.php`, `config/sanctum.php`,
  `config/services.php`, `config/session.php`, `config/view.php` — these now live inside the
  framework and are only published if you need to override them

> **Important:** These files are not deleted on upgrade — they stay in place and Laravel 11
> will read them. The new slim skeleton is for *new* projects. Existing L10 projects that
> upgrade to L11 keep their existing structure and it continues to work.

### Middleware

`App\Http\Middleware\TrustProxies` now has a different base class. If this repo overrides it,
check the signature.

`ThrottleRequests` middleware alias changed from `throttle` (still works but check explicitly).

### Service providers

The `AuthServiceProvider`, `EventServiceProvider`, and `RouteServiceProvider` pattern still
works in L11 but is considered legacy. Do not restructure providers during the upgrade itself.

### Eloquent

- `Model::preventLazyLoading()` is now called automatically in `APP_DEBUG=true` environments
  by the default service provider. This can surface N+1 warnings in tests. **Watch for this.**
- `casts()` method preferred over `$casts` array (old array still works)
- `withoutTimestamps()` added (minor)

### Database / Migrations

- Schema builder changes: `unsignedBigInteger()` auto-increments are now `id()` convention
  (existing migrations are unaffected)
- `doctrine/dbal` is no longer required by the framework for column modification. Laravel 11
  ships its own schema grammar that handles `ALTER TABLE ... MODIFY COLUMN`. The `doctrine/dbal`
  package can be removed *if* it is not used directly elsewhere.

Check usage:

```bash
grep -r "doctrine/dbal\|Doctrine\\\\DBAL" app/ --include="*.php"
```

If no direct usage, remove it from `composer.json` after the upgrade.

### Queue

No breaking changes for typical usage.

### HTTP Exceptions

`Symfony\Component\HttpKernel\Exception\HttpException` constructor signature is unchanged.

### Testing

PHPUnit 10 → 11 changes:
- `setUp()` and `tearDown()` must now declare `void` return type
- `@depends` annotation still works but `#[Depends]` attribute form is preferred
- `assertJson()` is stricter about encoding

Run the full test suite and fix any `setUp`/`tearDown` return type warnings.

---

## Phase 1 — Dependency bumps only (no code changes)

Update `composer.json` to the new version ranges. **Do not run `composer install` yet.** Review
all the constraints first.

### 1.1 `composer.json` changes

```json
{
    "require": {
        "php": "^8.2",
        "laravel/framework": "^11.0",
        "laravel/sanctum": "^4.0",
        "spatie/laravel-query-builder": "^6.0"
    },
    "require-dev": {
        "nunomaduro/collision": "^8.0",
        "nunomaduro/larastan": "^3.0",
        "phpstan/phpstan": "^2.0",
        "phpunit/phpunit": "^11.0"
    },
    "config": {
        "platform": {
            "php": "8.2.0"
        }
    }
}
```

### 1.2 Install and check for conflicts

```bash
composer update --dry-run 2>&1 | head -100
```

This will report dependency conflicts before any files change. Resolve them before running
the actual install.

### 1.3 Full install

```bash
composer update
```

---

## Phase 2 — Application bootstrap changes

### 2.1 `bootstrap/app.php`

Laravel 11 changes `bootstrap/app.php` from a bare Application instance to a fluent builder.
The existing file in this repo may look like:

```php
$app = new Illuminate\Foundation\Application(dirname(__DIR__));
$app->singleton(Http\Kernel::class, ...);
$app->singleton(Console\Kernel::class, ...);
$app->singleton(Exceptions\Handler::class, ...);
return $app;
```

Laravel 11's new format:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // middleware configuration
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // exception configuration
    })
    ->create();
```

> **However:** The old `bootstrap/app.php` continues to work in L11 if `App\Http\Kernel` exists.
> Do not restructure `bootstrap/app.php` during the initial upgrade. This is follow-up work.

### 2.2 Check `app/Http/Kernel.php`

If the file exists (it does in this repo since it was created as a L10 project), Laravel 11 will
use the `Kernel`-based bootstrap path automatically. No action needed.

---

## Phase 3 — Fix breaking changes

### 3.1 `spatie/laravel-query-builder` 5.x → 6.x

v6 requires changes to how `AllowedFilter` and `AllowedSort` are constructed. Run:

```bash
grep -r "AllowedFilter\|AllowedSort\|AllowedInclude" app/ --include="*.php" -l
```

Review each usage against the [v6 upgrade guide](https://github.com/spatie/laravel-query-builder/blob/main/UPGRADING.md).

### 3.2 `laravel/sanctum` 3.x → 4.x

Sanctum 4 requires the `sanctum.php` config to be published:

```bash
php artisan vendor:publish --tag=sanctum-config
```

Check the published config diff against the existing `config/sanctum.php`. The primary change is
the `guard` configuration key was renamed in some versions — verify the guards used.

### 3.3 PHPUnit 10 → 11

PHPUnit 11 requires `void` return types on `setUp` / `tearDown`:

```bash
# Find all setUp/tearDown methods missing void return type
grep -rn "function setUp()\|function tearDown()" tests/ --include="*.php"
```

Add `: void` to each occurrence.

### 3.4 Larastan 2 → 3 + PHPStan 1 → 2

PHPStan 2 changes some rule behaviours. Update `phpstan.neon`:

```neon
includes:
    - ./vendor/nunomaduro/larastan/extension.neon

parameters:
    paths:
        - app/
    level: 4
    # PHPStan 2 changed how some generics work — if errors appear, add to ignoreErrors
```

Run `composer run phpstan` and fix or suppress new errors.

### 3.5 Eloquent lazy loading prevention

Laravel 11's `AppServiceProvider` enables `preventLazyLoading` in debug mode by default. If
this wasn't set before, tests or dev pages may start showing `Illuminate\Database\LazyLoadingViolationException`.

Either add explicit `->with(...)` eager loads to the affected queries, or explicitly disable
it in `AppServiceProvider`:

```php
// app/Providers/AppServiceProvider.php
Model::preventLazyLoading(app()->isProduction());
```

---

## Phase 4 — Symfony 7 compatibility (if needed)

Laravel 11 ships Symfony 7 components (`^7.0`). Direct Symfony usage in this repo:

```bash
grep -r "Symfony\\\\" app/ routes/ --include="*.php" | grep -v vendor | head -20
```

Most Symfony 6 → 7 changes are internal. Check for:
- `Symfony\Component\HttpFoundation\Request::getContentType()` → renamed to `getContentTypeFormat()`
- `Symfony\Component\HttpFoundation\Response` constructor arguments — no change
- Any `Symfony\Component\Yaml\*` usage — check if `symfony/yaml` 7.x is compatible

---

## Phase 5 — Verification

```bash
# Check for obvious bootstrap errors
php artisan about

# Run migrations (on a test database)
php artisan migrate:fresh --seed

# Run PHPStan
./vendor/bin/phpstan analyse

# Run PHPUnit
php artisan test

# Build frontend (Vite)
corepack pnpm build

# Start dev server and smoke-test
php artisan serve
```

---

## Post-upgrade clean-up (separate PR)

After the upgrade is stable:

1. **Slim `bootstrap/app.php`** — migrate from Kernel-based to fluent builder
2. **Remove merged service providers** — fold `RouteServiceProvider` into `bootstrap/app.php`
3. **Slim config files** — remove config files that are now framework defaults
4. **`doctrine/dbal` removal** — if column introspection is handled by L11's built-in grammar
5. **`laravel/ui` removal** — if auth scaffolding is no longer needed

---

## Packages that do NOT need changes

These are compatible with Laravel 11 and require no version bumps:

- `laravel/cashier` ^15.4 — v15 explicitly supports L10 + L11
- `laravel/socialite` ^5.12 — compatible
- `laravel/tinker` ~2.10 — compatible
- `laravel/helpers` ~1.6 — compatible
- `laravel/sail` ~1.21 — compatible (bump to ~1.26 for L11 features)
- `guzzlehttp/guzzle` ~7.9 — compatible
- `hashids/hashids` ~5.0 — compatible
- `phpseclib/phpseclib` ~3.0 — compatible
- `pragmarx/google2fa` ~8.0 — compatible
- `predis/predis` ~2.1 — compatible
- `webmozart/assert` ~1.11 — compatible
- `aws/aws-sdk-php` ~3.368 — compatible
- *(mollie/mollie-api-php removed)*
- `lcobucci/jwt` ~4.3 — compatible
- `spatie/laravel-fractal` ~6.0 — compatible
- `spatie/laravel-ignition` ^2.5 — compatible
- All `league/flysystem-*` ~3.x — compatible

---

## Risk level

🔴 **Very large.** Recommend a dedicated feature branch with the following PR sequence:

| PR | Contents |
|---|---|
| **PR 1** | Dependency bumps only; fix install-time conflicts |
| **PR 2** | Fix `spatie/laravel-query-builder` 6.x usage |
| **PR 3** | PHPUnit 11 `void` return types; fix test failures |
| **PR 4** | PHPStan 2 + Larastan 3; fix or suppress new errors |
| **PR 5** | Full smoke test + CI green |
| **PR 6** (follow-up) | Slim `bootstrap/app.php` and providers (optional modernisation) |
