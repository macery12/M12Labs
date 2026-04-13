# Vite 5 Upgrade Guide

This document is a full, phased migration plan for upgrading the M12Labs frontend toolchain from
**Vite 4.5.14** to **Vite 5**, along with all recommended companion upgrades. Each section
explains *what* changes, *whether it can break anything*, and what the exact before/after looks
like for every touched file.

---

## Current state snapshot

| Package | Current version | Category |
|---|---|---|
| `vite` | 4.5.14 | Build tool |
| `@vitejs/plugin-react` | 3.1.0 | Vite plugin |
| `laravel-vite-plugin` | 0.7.4 | Vite plugin |
| `vitest` | 0.28.5 | Test runner |
| `happy-dom` | 8.7.2 | Test environment |
| `typescript` | 4.9.5 | Language tooling |
| `@types/node` | 18.14.1 | Type definitions |
| `@preact/preset-vite` | 2.10.3 | Vite plugin (unused) |
| Node.js (engines field) | `>=16.13` | Runtime requirement |

---

## Phase 0 — Pre-flight checks (no files changed)

These are environment checks that **must** be confirmed before any `package.json` line is touched.
Failing to do them first is the single most common cause of a broken upgrade.

### 0.1 Confirm Node 18+ across all environments

**Why:** Vite 5 hard-drops Node 16 support. If any environment (local dev, CI runner, Docker
container, production build server) is still on Node 16 the install or build will fail with a
peer-dependency error.

**Check:**
```bash
node --version   # must be v18.x or higher
```

Environments to verify:
- Local developer machines
- CI workflow runner image (e.g. `ubuntu-22.04` ships Node 18 by default; older images may not)
- Any Dockerfile that runs `pnpm build`

**Risk level:** 🔴 **Breaking** if Node 16 is still in use anywhere. Zero risk once Node 18+ is
confirmed.

### 0.2 Review CI pipeline for pinned Node versions

Look for `.nvmrc`, `.node-version`, or `node-version:` in workflow YAML files. If any of those
pin to `16.x`, they must be updated before or alongside this upgrade.

---

## Phase 1 — Hard blockers (build fails without these)

These three package bumps are **required**. Skipping any one of them prevents `pnpm install` or
`pnpm build` from completing after `vite` is bumped.

### 1.1 `vite` 4.5.14 → 5.x

**File:** `package.json` devDependencies  
**Change:** `"vite": "4.5.14"` → `"vite": "^5.4.0"`

**What breaks without it:** Nothing — this is the target of the whole upgrade.  
**What breaks because of it:** Everything else in this document if the companion packages are not
also updated. Do not bump `vite` in isolation.

**Vite 5 changes that affect this repo:**

| Change | Impact |
|---|---|
| Node 16 dropped | 🔴 Hard fail on Node 16 (see Phase 0) |
| `server.cors` default changed to `false` | ✅ No impact — `vite.config.ts` already sets `{ origin: '*' }` explicitly |
| `build.target` default is now ES2020-equivalent | ⚠️ Minor — see optional item in Phase 3 |
| Persistent FS transform cache added | ✅ Free performance win; no config needed |
| SSR-related API changes | ✅ No impact — this repo does not use Vite SSR |

---

### 1.2 `@vitejs/plugin-react` 3.1.0 → 4.x

**File:** `package.json` devDependencies  
**Change:** `"@vitejs/plugin-react": "3.1.0"` → `"@vitejs/plugin-react": "^4.3.0"`

**Why:** plugin-react 3.x declares `"vite": "^4.0.0"` as a peer dependency. pnpm will reject the
install with a peer-conflict error when Vite 5 is present.

**Does anything break in `vite.config.ts`?** No. The `react({ babel: { plugins: [...] } })` API
is identical between plugin-react 3 and 4. The import, the call signature, and the Babel plugin
list (`babel-plugin-macros`, `babel-plugin-styled-components`) all work unchanged.

**Risk level:** ✅ **Drop-in replacement.** No application code or config changes required.

---

### 1.3 `laravel-vite-plugin` 0.7.4 → 1.x

**File:** `package.json` devDependencies  
**Change:** `"laravel-vite-plugin": "0.7.4"` → `"laravel-vite-plugin": "^1.0.0"`

**Why:** 0.7.x hardcodes `"vite": "^4"` as a peer dependency. The install fails with Vite 5.
The 1.x line was released specifically to support Vite 5.

**Does anything break in the build output that Laravel reads?**

The PHP side reads the asset manifest from `public/build/manifest.json`. The manifest format
changed slightly between laravel-vite-plugin 0.7 and 1.x:

- In 0.7.x the manifest path was `public/build/manifest.json`  
- In 1.x the manifest path is `public/build/.vite/manifest.json`

> ⚠️ **This is a breaking change for the PHP template.** After updating the plugin you **must**
> do a full `pnpm build` and smoke-test the Laravel page load to confirm the new manifest path is
> resolved correctly by the `@vite()` Blade directive. If your app is deployed with a cached
> build artifact, that artifact must be regenerated — the old `manifest.json` location will no
> longer be read.

**Risk level:** ⚠️ **Breaking for the PHP manifest path.** Trivially fixed by running a new
build; the `vite.config.ts` itself needs no changes.

---

## Phase 2 — Test infrastructure (test suite fails without these)

These changes are required for `corepack pnpm test` to pass after the Vite 5 upgrade.

### 2.1 `vitest` 0.28.5 → 1.x

**File:** `package.json` devDependencies  
**Change:** `"vitest": "0.28.5"` → `"vitest": "^1.6.0"`

**Why:** vitest 0.x is tightly coupled to Vite 4 internals. With Vite 5 present, vitest 0.28.5
will fail at startup with internal module resolution errors before any test runs.

**Does the test file pattern change?** No. `resources/scripts/**/*.{spec,test}.{ts,tsx}` is still
the correct include glob for vitest 1.x.

**Does `environment: 'happy-dom'` still work?** Yes, but `happy-dom` itself must also be bumped
(see 2.2 below).

**vitest 0.x → 1.x notable behaviour changes:**

| Change | Impact |
|---|---|
| `vi.fn()` and `vi.spyOn()` mock reset behaviour is slightly more consistent | ✅ No impact for typical usage |
| `expect.assertions()` failures now report clearly | ✅ Better DX only |
| `--reporter` default changed from `verbose` to `default` | ✅ No test failures, just different console output |
| `test.concurrent` is now truly concurrent (was partially serial in 0.x) | ⚠️ Unlikely to matter here, but worth scanning for `test.concurrent` usage |

**Risk level:** ⚠️ **Tests will not run at all** on Vite 5 with vitest 0.x. Once bumped,
existing tests should pass without changes in the vast majority of cases.

---

### 2.2 `happy-dom` 8.7.2 → 14.x

**File:** `package.json` devDependencies  
**Change:** `"happy-dom": "8.7.2"` → `"happy-dom": "^14.0.0"`

**Why:** vitest 1.x dropped compatibility with the internal environment provider API that
happy-dom 8.x used. Running tests with the old happy-dom on vitest 1.x results in runtime errors
like `Cannot read properties of undefined` inside the happy-dom environment setup, not in the
application code.

**Does this affect test behaviour?** Possibly in edge cases. happy-dom 14 is significantly more
spec-compliant than 8.7. Tests that relied on happy-dom 8's looser DOM API behaviour (e.g.
missing `AbortController`, missing `structuredClone`, older `fetch` mock behaviour) may need
minor updates. In practice this repo's test suite is primarily unit tests against component
output, so the risk is low.

**Risk level:** ⚠️ **Tests will error** (not fail, error) on vitest 1.x with happy-dom 8.x. Post-
bump, run the full suite and fix any newly failing tests.

---

### 2.3 `vite.config.ts` — switch `defineConfig` source for vitest

**File:** `vite.config.ts`

**Current (vitest 0.x style):**
```typescript
/// <reference types="vitest" />
import { defineConfig } from 'vite';
```

**After (vitest 1.x style):**
```typescript
import { defineConfig } from 'vitest/config';
```

**Why:** vitest 1.x moved the type augmentation for the `test:` config block into the
`vitest/config` module. The triple-slash reference still technically works but produces incorrect
TypeScript intellisense — the `test` property will show as `unknown`. Importing `defineConfig`
from `vitest/config` is the official supported pattern and re-exports everything from `vite`
internally, so the rest of the config is unaffected.

**Does this change the runtime behaviour of the build?** No. It is a pure TypeScript type-source
change.

**Risk level:** ✅ **Non-breaking.** One line to remove, one line to change.

---

## Phase 3 — Recommended (non-breaking, but strongly advised)

These changes are not required for the build or tests to pass, but they align the project with
Vite 5 and TypeScript 5 best practices. They can be done in the same PR or deferred.

### 3.1 `tsconfig.json` — `moduleResolution: "bundler"`

**File:** `tsconfig.json`  
**Change:** `"moduleResolution": "Node"` → `"moduleResolution": "bundler"`

**Why:** `"Node"` resolution is the pre-ESM algorithm and does not understand the `exports` field
in modern packages (e.g. `exports` conditions like `"import"`, `"default"`). Since Vite handles
all actual module resolution at build time (tsc is `noEmit: true`), `"bundler"` is the correct
setting — it tells TypeScript to mirror how a bundler (Vite/Rollup) would resolve imports.

**Could this surface new errors?** Yes — but they are all **real problems** that were being
silently masked. If tsc starts complaining about a package after this change, the import was
already wrong; it just happened to resolve because `"Node"` resolution is permissive.

**Recommended approach:** make this change on its own commit so that any tsc errors it uncovers
are clearly attributable to it, not to the Vite 5 package bumps.

**Risk level:** ✅ **Non-breaking at runtime.** May surface new tsc type errors pointing to real
import issues.

---

### 3.2 `package.json` — `engines.node` field

**File:** `package.json`  
**Change:** `"node": ">=16.13"` → `"node": ">=18"`

**Why:** Documents the true minimum Node version that Vite 5 (and therefore this project) will
work on. Without this, developers on Node 16 get a confusing Vite install error with no
explanation pointing to the `engines` field.

**Risk level:** ✅ **Zero.** Informational only.

---

### 3.3 `vite.config.ts` — explicit `build.target`

**File:** `vite.config.ts`  
**Change:** add `build: { target: 'es2019' }` to the existing `build` config block

**Why:** Vite 5's built-in transform target defaults shifted slightly between versions. The
`tsconfig.json` already specifies `"target": "ES2019"`. Making it explicit in `vite.config.ts`
aligns both tools and prevents any future Vite default change from silently producing wider ES
output than intended.

**Risk level:** ✅ **Zero.** Codifies the existing implied behaviour.

---

### 3.4 Remove unused `@preact/preset-vite`

**File:** `package.json` devDependencies  
**Change:** remove `"@preact/preset-vite": "^2.10.3"` entirely

**Why:** `@preact/preset-vite` is listed as a devDependency but is never imported in
`vite.config.ts`. The repo instead uses `@vitejs/plugin-react` with manual `resolve.alias`
entries to map `react` → `preact/compat`. The preset is dead weight; removing it avoids the
unnecessary install and eliminates a package that will itself eventually have Vite 5
compatibility issues.

**Risk level:** ✅ **Zero.** The package is not referenced anywhere in the codebase.

---

### 3.5 `typescript` 4.9.5 → 5.x (optional, follow-up PR)

**File:** `package.json` devDependencies  
**Change:** `"typescript": "4.9.5"` → `"typescript": "^5.4.0"`

**Why:** vitest 1.x and Vite 5 are primarily tested with TypeScript 5.x. TS 4.9 continues to
work, but you will start seeing deprecation notices from some tooling. TypeScript 5.0 breaking
changes are minor for this project because:

- `useDefineForClassFields: true` is already set in `tsconfig.json`, which is how TS 5 behaves
  by default anyway
- TS 5 removed `--importsNotUsedAsValues` in favour of `verbatimModuleSyntax`, but this project
  does not use that flag

**Suggested approach:** Do this in a separate PR from the Vite 5 upgrade. tsc errors from a TS
major bump are easier to debug when the build toolchain change is already stable.

**Risk level:** ⚠️ Low. Likely zero tsc errors in this codebase given the tsconfig settings, but
worth isolating to its own PR.

---

### 3.6 `@types/node` 18.14.1 → 20.x (follow-up, pairs with TS 5)

**File:** `package.json` devDependencies  
**Change:** `"@types/node": "18.14.1"` → `"@types/node": "^20.0.0"`

**Why:** Pairs with the Node 18 minimum bump. Node 20 types add APIs that Node 18 also ships and
document several previously un-typed Node internals. Should be done at the same time as the TS 5
upgrade.

**Risk level:** ✅ **Additive only.** New type declarations do not break existing code.

---

## Phase 4 — Verification steps

After each phase's changes are installed and built, run these checks in order.

### 4.1 Install

```bash
corepack pnpm install
```

Check the output for peer-dependency warnings. After Phase 1 + 2 are applied there should be
zero peer warnings involving `vite`, `vitest`, or the React plugin.

### 4.2 Build

```bash
corepack pnpm build
```

Expected: same chunk structure as before (vendor-editor-core, vendor-terminal, vendor-charts,
vendor-stripe, vendor-state, vendor-motion, vendor-styled, vendor-react).

On the **second** run, the Vite 5 FS cache kicks in:

```bash
corepack pnpm build   # second run — should be significantly faster
```

### 4.3 Tests

```bash
corepack pnpm test
```

All existing tests should pass. If any fail after the happy-dom bump, check whether the failure
is in DOM API behaviour (the test environment changed) or in application logic (a real bug).

### 4.4 Dev server smoke test

```bash
corepack pnpm dev
```

- Page loads without console errors
- HMR works (edit a `.tsx` file and confirm the browser updates)
- Preact aliases resolve (app renders correctly — React-style components should work via
  `preact/compat`)

### 4.5 Full Laravel page load

After `pnpm build`, load the Laravel-served page in a browser and confirm:

- Assets load (no 404s for JS/CSS chunks)
- The `@vite()` Blade directive resolves the new manifest at
  `public/build/.vite/manifest.json`

This is the one test that confirms the `laravel-vite-plugin` 1.x manifest path change (Phase 1.3)
is working.

---

## Full change summary table

| # | File | Change | Breaking? | Notes |
|---|---|---|---|---|
| 0.1 | Environment | Node 16 → 18+ | 🔴 If not done | Check CI, Docker, local |
| 1.1 | `package.json` | `vite` 4.5.14 → ^5.4.0 | Prerequisite | Do with 1.2 + 1.3 |
| 1.2 | `package.json` | `@vitejs/plugin-react` 3.1.0 → ^4.3.0 | ✅ Drop-in | No config change |
| 1.3 | `package.json` | `laravel-vite-plugin` 0.7.4 → ^1.0.0 | ⚠️ Manifest path | Must rebuild + verify |
| 2.1 | `package.json` | `vitest` 0.28.5 → ^1.6.0 | ⚠️ Tests won't run on 0.x + Vite 5 | |
| 2.2 | `package.json` | `happy-dom` 8.7.2 → ^14.0.0 | ⚠️ Test env API changed | Minor test fixes possible |
| 2.3 | `vite.config.ts` | `defineConfig` from `vitest/config` | ✅ Non-breaking | Type accuracy fix |
| 3.1 | `tsconfig.json` | `moduleResolution: "bundler"` | ✅ Runtime safe | May surface tsc errors |
| 3.2 | `package.json` | `engines.node` → `>=18` | ✅ Informational | |
| 3.3 | `vite.config.ts` | Add `build.target: 'es2019'` | ✅ Non-breaking | Codifies existing behaviour |
| 3.4 | `package.json` | Remove `@preact/preset-vite` | ✅ Zero risk | Package is unused |
| 3.5 | `package.json` | `typescript` 4.9.5 → ^5.4.0 | ✅ Low risk | Separate PR recommended |
| 3.6 | `package.json` | `@types/node` 18.x → ^20.0.0 | ✅ Additive | Pair with TS 5 PR |

---

## Recommended PR sequence

| PR | Contents | Risk |
|---|---|---|
| **PR 1** | Phases 1 + 2 + 3.1–3.4 | Medium — full test + Laravel smoke test required |
| **PR 2** | Phase 3.5 + 3.6 (TS 5 + @types/node 20) | Low — isolated TS compiler bump |

Keeping TS 5 in a separate PR makes it easy to bisect any type errors that appear — you know
they came from the compiler version, not from Vite or vitest.

---

## Packages confirmed unchanged

These packages require **zero changes** for the Vite 5 upgrade and are listed here explicitly to
avoid confusion:

- All `@codemirror/*` and `@lezer/*` packages — pure library code, no Vite dependency
- `preact` and all `react`/`react-dom` aliases — alias resolution in `vite.config.ts` unchanged
- `styled-components` and `babel-plugin-styled-components` — run inside Babel, unaffected
- `twin.macro` and `babel-plugin-macros` — run inside Babel, unaffected
- `tailwindcss`, `postcss`, `autoprefixer` — PostCSS pipeline unchanged in Vite 5
- `@tailwindcss/forms`, `@tailwindcss/line-clamp` — pure PostCSS plugins
- All `eslint` packages — ESLint runs independently of Vite
- `framer-motion`, `chart.js`, `xterm` — application libraries, no Vite coupling
- `axios`, `swr`, `formik`, `react-router` — application libraries, no Vite coupling
