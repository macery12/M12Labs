# Outdated and Deprecated Packages (Post Laravel 11 Upgrade)

Date: 2026-05-31

## Scope

This report captures packages identified during Backend Step A (Laravel 10 -> 11), plus key deprecated frontend packages discovered in the workspace audit.

## Backend: Replaced During This Step

- `laravel/helpers` (removed)
  - Reason: package does not support Laravel 11 (`illuminate/support` only through `^10`).
  - Replacement: local compatibility helpers were added in `app/helpers.php` for legacy calls (`array_get`, `snake_case`, `str_random`).

- `nunomaduro/larastan` (abandoned package name) -> `larastan/larastan`
  - Reason: Composer flags `nunomaduro/larastan` as abandoned.
  - Replacement: migrated to `larastan/larastan`.

## Backend: Abandoned/Deprecated Still Present (Transitive)

- `doctrine/cache` (abandoned)
  - Status: transitive dependency warning emitted by Composer.
  - Replacement guidance: no official direct replacement declared by Composer for this tree.
  - Action: track upstream packages and remove once no longer required transitively.

## Backend: Remaining Notable Outdated Direct Packages

From `composer outdated --direct` after upgrade:

- `laravel/framework` `11.54.0` -> `13.12.0` (expected; next roadmap steps 11 -> 12 -> 13)
- `phpunit/phpunit` `10.5.63` -> `12.5.28`
- `phpstan/phpstan` `1.12.33` -> `2.2.1`
- `larastan/larastan` `2.11.2` -> `3.10.0`
- `symfony/http-client` `6.4.33` -> `7.4.13`
- `predis/predis` `2.1.2` -> `3.4.2`
- `stripe/stripe-php` `16.6.0` -> `20.2.0`

## Frontend: Deprecated Packages Replaced

From `pnpm outdated --format=table` and current `package.json`:

- `@floating-ui/react-dom-interactions` `0.13.3` (Deprecated) -> `@floating-ui/react` `0.27.19`

## Frontend: Legacy Namespace Packages Replaced

- `xterm` -> `@xterm/xterm` `6.0.0`
- `xterm-addon-fit` -> `@xterm/addon-fit` `0.11.0`
- `xterm-addon-search` -> `@xterm/addon-search` `0.16.0`
- `xterm-addon-web-links` -> `@xterm/addon-web-links` `0.12.0`

Note:

- There is no `@xterm/addon-search-bar` package on npm; the old `xterm-addon-search-bar` dependency was removed and console search now uses the scoped search addon directly via Ctrl/Cmd+F prompt.

## Notes

- Backend step A completed dependency migration to Laravel 11 with compatible package versions.
- Some quality gate failures are environmental in this workspace (`pdo_sqlite` driver missing), not dependency-resolution blockers.

---

## Backend Step C (Laravel 12 -> 13) Upgrade Log

Date: 2026-06-01

### Dependency outcome

- `composer update --with-all-dependencies` completed successfully as `www-data`.
- `composer outdated --direct --locked` now reports all direct dependencies up to date.
- Key major upgrades applied:
  - `laravel/framework` `12.61.0` -> `13.12.0`
  - `phpunit/phpunit` `11.5.55` -> `12.5.28`
  - `doctrine/dbal` `3.6.7` -> `4.4.3`
  - `predis/predis` `2.1.2` -> `3.4.2`
  - `spatie/laravel-query-builder` `6.4.4` -> `7.3.0`
  - `stripe/stripe-php` `16.6.0` -> `20.2.0`
  - `symfony/http-client` `6.4.33` -> `7.4.13`
  - `webmozart/assert` `1.11.0` -> `2.4.0`

### Refactor Task List (required due function/signature/API changes)

1. Restore removed legacy string helper usage.
  - Failure: `Call to undefined function ... title_case()` during tests.
  - File hotspot: `app/Traits/Helpers/AvailableLanguages.php`.
  - Action: replace `title_case(...)` with `Illuminate\Support\Str::title(...)` (or an equivalent project helper shim).

2. Migrate PHPUnit data providers to PHPUnit 12 metadata.
  - Failure: `Too few arguments ... 0 passed and exactly 1 expected` for tests using docblock `@dataProvider`.
  - File hotspots include:
    - `tests/Integration/Api/Client/Server/Startup/UpdateStartupVariableTest.php`
    - `tests/Integration/Api/Client/Server/Subuser/CreateServerSubuserTest.php`
    - `tests/Integration/Api/Client/Server/Subuser/SubuserAuthorizationTest.php`
  - Action: replace docblock-style providers with PHPUnit 12 attributes (for example `#[DataProvider('permissionsDataProvider')]`) and ensure provider visibility/signatures match PHPUnit 12 requirements.

3. Update Guzzle exception handling assumptions.
  - PHPStan reports `GuzzleException::hasResponse()` / `getResponse()` as undefined.
  - File hotspots:
    - `app/Services/Mods/CurseForgeService.php`
    - `app/Services/Mods/ModrinthService.php`
  - Action: narrow caught exceptions to response-capable exception types (for example request exceptions) or gate response access by instance checks.

4. Address typed return mismatch in JWT service.
  - PHPStan reports return type mismatch: expected `Token\Plain`, got `UnencryptedToken`.
  - File hotspot: `app/Services/Nodes/NodeJWTService.php`.
  - Action: align declared return type with actual concrete token type returned by current `lcobucci/jwt` API.

5. Fix DateTime mutability typing in email type registry.
  - PHPStan reports undefined method `DateTimeInterface::setTimezone()`.
  - File hotspot: `app/Services/Email/EmailTypeRegistry.php`.
  - Action: either type to mutable `DateTime` or convert via immutable-safe flow and return adjusted instance explicitly.

6. Resolve model/repository method contract drift surfaced by stricter analysis.
  - Representative failures:
    - Undefined methods/properties on generic `Model` in multiple services.
    - `ThemeRepositoryInterface::get()` invoked with wrong arity.
    - `User::createAsStripeCustomer()` reported undefined.
  - Action: add/refresh precise model and repository typing (including PHPDoc generics or concrete model hints), then correct mismatched call signatures.

7. Clean up nullsafe/null-coalescing patterns now flagged as always true/false.
  - Widespread PHPStan findings (nullsafe on non-null values, redundant `??`, always-true comparisons).
  - Action: simplify conditions in reported files to satisfy stricter PHPStan 2/Larastan 3 rules and prevent dead-path logic.
