# Upgrade Roadmap (May 2026)

This is the current, canonical plan for bringing this repository to a fully modern baseline:

- Backend target: Laravel 13
- Frontend target: Vite 8
- PHP target: 8.3 (recommended floor for this project)

Use this file as the entry point and execute upgrades in the sequence below.

---

## Current baseline (audited)

- PHP: `8.3.30`
- Node: `18.19.1`
- pnpm: `9.0.6`
- `laravel/framework`: `10.48.29`
- `vite`: `5.4.21`
- `tailwindcss`: `3.2.7`

### Important environment notes

- Laravel 13 requires PHP 8.3+.
- Tailwind v4 upgrade tooling requires Node 20+.
- Vite 8 should also be done on modern Node (use Node 20+ for all frontend modernization).

---

## Execution order (one major per PR)

1. Backend step A: Laravel 10 -> 11
2. Backend step B: Laravel 11 -> 12
3. Backend step C: Laravel 12 -> 13
4. Frontend step A: Vite 5 -> 6 -> 7 -> 8 (one major per PR)
5. Frontend step B: Tailwind 3.2 -> 3.4 (stabilize), then 3.4 -> 4 using upgrade tool
6. Cleanup and lockfile refresh pass for remaining direct dependencies

Use dedicated plan files:

- `LARAVEL11_UPGRADE.md`
- `LARAVEL12_UPGRADE.md`
- `LARAVEL13_UPGRADE.md`
- `FRONTEND_VITE8_TAILWIND4_PLAN.md`
- `PACKAGE_AUDIT.md`

---

## Release strategy rules

- One framework major per PR.
- No mixed backend + frontend framework majors in one PR.
- Keep each PR CI-green before starting next phase.
- Prefer constraint bumps in `composer.json` / `package.json`, then resolve code breaks.
- Run full tests and build after every phase.

---

## Fast command checklist

Backend audit:

```bash
composer outdated --direct
```

Frontend audit:

```bash
pnpm outdated --format=table
```

Backend quality gate:

```bash
php artisan about
php artisan test
./vendor/bin/phpstan analyse
```

Frontend quality gate:

```bash
pnpm build
pnpm test
```

---

## Definition of done (program targets)

- `laravel/framework` on `^13.0`
- PHP constraint set to `^8.3`
- `vite` on `^8`
- Tailwind either:
  - stabilized on latest v3.4 if browser support requires it, or
  - fully migrated to v4 with validated UI diffs
- Critical deprecated packages removed/replaced (especially old `xterm` namespace and `@floating-ui/react-dom-interactions`)
