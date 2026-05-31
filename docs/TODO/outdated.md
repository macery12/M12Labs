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
