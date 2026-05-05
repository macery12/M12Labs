# Dependency Audit

Full audit of outdated and deprecated dependencies across the frontend (`package.json`) and backend
(`composer.json`). Conducted after the Vite 5 upgrade (April 2026).

---

## Already done

| Package | From | To | PR |
|---|---|---|---|
| `vite` | 4.5.14 | 5.4.x | Vite 5 upgrade PR |
| `@vitejs/plugin-react` | 3.1.0 | 4.7.x | Vite 5 upgrade PR |
| `laravel-vite-plugin` | 0.7.4 | 1.3.x | Vite 5 upgrade PR |
| `vitest` | 0.28.5 | 1.6.x | Vite 5 upgrade PR |
| `happy-dom` | 8.7.2 | 20.8.9 | Security patch PR |

---

## Quick wins — low risk, one PR each

These are minor/patch bumps or isolated upgrades with no API surface changes.

| Package | Current | Target | Notes |
|---|---|---|---|
| `typescript` | 4.9.5 | `^5.4.0` | See `TYPESCRIPT5_UPGRADE.md` |
| `@types/node` | 18.14.1 | `^20.0.0` | Pair with TS 5 PR |
| `pnpm` (packageManager) | 9.0.6 | 9.15.x | Update `packageManager` field in `package.json`; run `corepack use pnpm@9` |
| `tailwindcss` | 3.2.7 | `^3.4.0` | Tailwind 3 patch; drop-in safe; Tailwind 4 is a separate, larger migration |
| `swr` | 2.0.3 | `^2.2.5` | Minor API additions only |
| `@testing-library/react` | 14.0.0 | `^16.0.0` | Requires React 18+; drop-in for this codebase |
| `@testing-library/user-event` | 14.4.3 | `^14.5.2` | Patch only |
| `predis/predis` | 2.1.x | `^2.3.0` | Patch; no API changes for usage in this repo |
| `phpunit/phpunit` | 10.5.x | `^11.0` | Requires PHP 8.2+; see note on PHP constraint below |
| `nunomaduro/collision` | 7.0.x | `^8.0` | Pairs with PHPUnit 11 and Laravel 11 |

> **PHP constraint note:** `composer.json` currently declares `"php": "^8.1 || ^8.2"` but the
> production server is running PHP 8.3. Tightening to `"^8.2"` unblocks PHPUnit 11 and is safe.
> Do this in the same PR as PHPUnit 11.

---

## Medium complexity — own PR, requires testing

These involve API surface or configuration changes but are well-understood and scoped.

### Frontend

| Package | Current | Target | Complexity | Detail |
|---|---|---|---|---|
| `@heroicons/react` | 1.0.6 | `^2.0.0` | 🟡 Medium | 78 files, import path + 30 icon renames — see `HEROICONS_V2_MIGRATION.md` |
| `@headlessui/react` | 1.7.11 | `^2.0.0` | 🟡 Medium | 10 files; Dialog/Transition API changes |
| `axios` | 0.30.3 | `^1.7.0` | 🟡 Medium | 15 files; error object shape changed — see below |
| `xterm` + addons | 5.1.0 | `@xterm/xterm ^5.5.0` | 🟡 Medium | 2 files; pure package rename — see below |
| `@floating-ui/react-dom-interactions` | 0.13.3 | `@floating-ui/react ^0.26.0` | 🟢 Low | 1 file (`Tooltip.tsx`); package renamed, same API |
| `eslint` | 8.34.0 | `^9.0.0` | 🟡 Medium | Flat config required — see `ESLINT9_UPGRADE.md` |
| `@typescript-eslint/*` | 5.53.0 | `^8.0.0` | 🟡 Medium | Pairs with ESLint 9 PR |

### Backend

| Package | Current | Target | Complexity | Detail |
|---|---|---|---|---|
| `phpstan/phpstan` | 1.10.x | `^2.0` | 🟡 Medium | Some rule signature changes; PHPDoc changes |
| `nunomaduro/larastan` | 2.4.x | `^3.0` | 🟡 Medium | Must pair with PHPStan 2; compatible with Laravel 10 |
| `doctrine/dbal` | 3.6.7 | `^4.0` | 🟡 Medium | API changes in schema introspection; used by Laravel migrations |
| `lcobucci/jwt` | 4.3.0 | `^5.0` | 🟡 Medium | Builder/parser API rework; check all JWT usage |

---

## Large upgrades — requires detailed planning

These are major version bumps with significant migration effort. Each has its own document or is
described in detail below.

### Frontend

| Package | Current | Target | Effort | Plan |
|---|---|---|---|---|
| `date-fns` | 2.29.3 | `^3.0.0` | 🔴 Large | 34 files — see below |
| `framer-motion` | 9.1.6 | `^11.0` | 🟡 Medium | 2 files; v10/11 removed legacy props |
| `chart.js` | 3.9.1 | `^4.0.0` | 🟡 Medium | 4 files; dataset option renames |
| `styled-components` | 5.3.6 | `^6.0.0` | 🔴 Large | 249 files; pairs with `twin.macro` 3.x |
| `react` + `react-dom` | 18.2.0 | `^19.0.0` | 🔴 Large | Full suite test required; check `preact/compat` for React 19 support first |
| `react-router` + dom | 6.30.2 | `^7.0.0` | 🔴 Large | 128 files; v7 is data-router-first, some hook changes |

### Backend

| Package | Current | Target | Effort | Plan |
|---|---|---|---|---|
| `laravel/framework` | 10.48.29 | `^11.0` | 🔴 Very Large | See `LARAVEL11_UPGRADE.md` |

---

## Deprecated packages — action required (no upgrade, replace)

These packages are officially deprecated and will never receive security patches.

| Package | Status | Files | Replacement |
|---|---|---|---|
| `xterm`, `xterm-addon-*` | ⛔ Deprecated | 2 | `@xterm/xterm`, `@xterm/addon-*` — drop-in rename |
| `@floating-ui/react-dom-interactions` | ⛔ Renamed | 1 | `@floating-ui/react` — same API |
| `rimraf` | ⚠️ v3 deprecated | 0 (script only) | `rimraf@^5` or Node `fs.rm` |
| `eslint` 8.x | ⚠️ EOL | devDep | ESLint 9 — see `ESLINT9_UPGRADE.md` |
| `eslint-plugin-node` | ⚠️ Archived | devDep | `eslint-plugin-n` (maintained fork) |
| `@fortawesome/react-fontawesome` 0.2 | ⚠️ Deprecated | many | `@fortawesome/react-fontawesome@^3.1` |
| `styled-components-breakpoint` | ⚠️ Abandoned (preview) | many | Use Tailwind responsive variants instead |
| `use-persisted-state` | ⚠️ Unmaintained | some | Inline implementation or `zustand` `persist` |
| `@flyyer/use-fit-text` | ⚠️ Unmaintained | some | `react-fit-text` or direct CSS |

---

## Detailed notes for medium upgrades

### `axios` 0.30.x → 1.x (15 files)

**Breaking change:** The `AxiosError` type changed — `error.response?.data` is now typed as
`unknown` instead of `any`. Code that accesses nested properties needs explicit type assertions.

**Files to check:** `api/http.ts`, `api/interceptors.ts`, and the 13 route files that `import http`
from `api/http.ts`. The actual `axios` API (`axios.create`, `.get`, `.post`, `.interceptors`) is
unchanged.

**Migration steps:**
1. Bump `"axios": "^1.7.0"` in `package.json`
2. Run build — tsc will report any type narrowing failures
3. In `api/http.ts` and `api/interceptors.ts`, add explicit type assertions where `error.response?.data` is accessed
4. Run `corepack pnpm test` and verify

### `xterm` 5.1.0 → `@xterm/xterm` 5.5.x (2 files)

**Why:** The `xterm` package on npm is deprecated. The org moved to the `@xterm` namespace.

**Files:** `components/server/console/Console.tsx`, `plugins/XtermScrollDownHelperAddon.ts`

**Migration steps:**
1. Remove `xterm`, `xterm-addon-fit`, `xterm-addon-search`, `xterm-addon-search-bar`,
   `xterm-addon-web-links` from `package.json`
2. Add `@xterm/xterm`, `@xterm/addon-fit`, `@xterm/addon-search`, `@xterm/addon-web-links`
   (note: `xterm-addon-search-bar` has no official `@xterm` equivalent — check if still maintained
   or implement inline)
3. Update all imports in the 2 affected files:
   - `import { Terminal } from 'xterm'` → `import { Terminal } from '@xterm/xterm'`
   - `import { FitAddon } from 'xterm-addon-fit'` → `import { FitAddon } from '@xterm/addon-fit'`
   - `import { SearchAddon } from 'xterm-addon-search'` → `import { SearchAddon } from '@xterm/addon-search'`
   - `import { WebLinksAddon } from 'xterm-addon-web-links'` → `import { WebLinksAddon } from '@xterm/addon-web-links'`
   - CSS: `import 'xterm/css/xterm.css'` → `import '@xterm/xterm/css/xterm.css'`
4. Build and smoke-test the console

### `@floating-ui/react-dom-interactions` → `@floating-ui/react` (1 file)

**File:** `resources/scripts/elements/tooltip/Tooltip.tsx`

**Migration steps:**
1. Remove `@floating-ui/react-dom-interactions` from `package.json`
2. Add `@floating-ui/react@^0.26.0`
3. Update import in `Tooltip.tsx`: `from '@floating-ui/react-dom-interactions'` → `from '@floating-ui/react'`
4. All exports used (`arrow`, `autoUpdate`, `flip`, `offset`, `Placement`, `shift`, `Side`,
   `useClick`, `useDismiss`, `useFloating`, `useFocus`, `useHover`, `useInteractions`, `useRole`)
   exist in `@floating-ui/react` with identical signatures

### `date-fns` 2.x → 3.x (34 files)

**Breaking changes:**
- All functions are now ESM-only (no CJS named exports) — handled by Vite automatically
- `format` tokens: `D` (day of year) and `Y` (week year) now warn if used without explicit locale
- `parse` strict mode is now the default
- `formatISO`, `parseISO` behavior is unchanged

**Functions used in this repo:** `format`, `formatDistanceToNow`, `formatDistanceToNowStrict`,
`differenceInDays`, `differenceInHours`, `eachDayOfInterval`, `endOfDay`, `isPast`,
`isWithinInterval`, `parseISO`, `startOfDay` — all are present in v3 with identical signatures.

**Migration steps:**
1. Bump `"date-fns": "^3.0.0"` in `package.json`
2. Run `corepack pnpm build` — if any type errors appear, they are real usage issues
3. Run `corepack pnpm test` to verify

**Risk:** Low — the functions this repo uses are all unchanged in v3.

### `framer-motion` 9.x → 11.x (2 files)

**Files:** Check with `grep -r "framer-motion" resources/scripts --include="*.tsx" -l`

The API used in this repo is basic (`motion`, `AnimatePresence`) — unchanged in v10/v11.
The only breaking change that might apply is `layoutId` handling and the removal of some
legacy `MagicMotion` APIs, which are not used here. Bump and test.

### `chart.js` 3.x → 4.x (4 files)

**Breaking changes that may affect this repo:**
- `maintainAspectRatio` default changed
- Dataset `parsing` option moved
- Some TypeScript generic signatures changed

**Files:** `console/chart.ts`, `console/StatGraphs.tsx`, `billing/overview/SuccessChart.tsx`,
`billing/overview/RevenueChart.tsx`

Build and run — tsc will surface any type mismatches.

### `@headlessui/react` 1.x → 2.x (10 files)

**Breaking changes:**
- `Dialog` no longer requires `onClose` to receive the keyboard-close event — now uses `onClose`
  callback consistently
- `Transition` API simplified — `Transition.Root` removed, use `Transition` directly
- `Combobox` internal ref handling changed
- Component props are more strictly typed

Check the 10 affected files for `Transition.Root`, `Dialog.Overlay`, and manual ref forwarding.

---

## Upgrade priority order

```
Immediate (next PR):
  1. TypeScript 5 + @types/node 20       (see TYPESCRIPT5_UPGRADE.md)
  2. pnpm 9.x latest + tailwindcss 3.4   (trivial, bundle with TS 5 PR)

Short-term (one PR each):
  3. xterm → @xterm                      (2 files, deprecated)
  4. @floating-ui rename                 (1 file, deprecated)
  5. axios 0.x → 1.x                    (15 files, error typing)
  6. @heroicons/react 1 → 2              (see HEROICONS_V2_MIGRATION.md)
  7. PHPStan 2 + Larastan 3             (dev tooling only)

Medium-term (one PR each):
  8. date-fns 2 → 3                     (34 files, low risk)
  9. chart.js 3 → 4                     (4 files)
  10. framer-motion 9 → 11              (2 files)
  11. @headlessui/react 1 → 2           (10 files)
  12. ESLint 9 flat config              (see ESLINT9_UPGRADE.md)

Planned (separate PRs, needs spike):
  13. styled-components 5 → 6           (249 files + twin.macro 3)
  14. react-router 6 → 7               (128 files)
  15. React 18 → 19                    (full suite test)
  16. Laravel 10 → 11                  (see LARAVEL11_UPGRADE.md)
```
