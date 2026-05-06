# TypeScript 5 Upgrade Guide

This is **Phase 3.5 + 3.6** from `VITE5_UPGRADE.md`, now in its own document. TypeScript 5 and
the matching `@types/node` update were intentionally deferred from the Vite 5 PR to make any type
errors easy to attribute to the compiler version change rather than the toolchain change.

---

## Current state

| Package | Current | Target |
|---|---|---|
| `typescript` | 4.9.5 | `^5.4.0` |
| `@types/node` | 18.14.1 | `^20.0.0` |

---

## Phase 1 — TypeScript 5

### 1.1 What changes in TS 5

| Change | Impact on this repo |
|---|---|
| `--declaration` now emits `using`/`await using` (ES2022 resource management) | ✅ Not used here |
| `--verbatimModuleSyntax` replaces `--importsNotUsedAsValues` | ✅ This repo does not set `importsNotUsedAsValues` |
| `enum` declarations across merged namespaces stricter | ✅ No ambient enums used here |
| `const` type parameters (`const T`) | ✅ Additive only |
| Decorators standard (stage 3 TC39) | ✅ Not used here |
| `@satisfies` operator | ✅ Additive only |
| `--moduleResolution bundler` (already done in Vite 5 PR) | ✅ Already in `tsconfig.json` |
| `useDefineForClassFields: true` is now the TS 5 default | ✅ Already explicitly set in `tsconfig.json` |

**Summary:** No known breaking changes apply to this codebase. The `tsconfig.json` already
uses the settings that align with TS 5 defaults.

### 1.2 `package.json` change

```json
"typescript": "^5.4.0"
```

### 1.3 Verification

After bumping and running `corepack pnpm install --no-frozen-lockfile`:

```bash
# Confirm the installed version
./node_modules/.bin/tsc --version
# Expected: Version 5.4.x

# Run the full build (runs tsc internally via vite)
corepack pnpm build

# Run tests
corepack pnpm test
```

If `tsc` surfaces new errors, they are **real problems** that TS 4.9 was missing — do not
suppress them with `@ts-ignore`. Fix each one before merging.

---

## Phase 2 — `@types/node` 20

### 2.1 What changes

`@types/node` 20 adds type declarations for Node 20 APIs. It does not remove any Node 18 APIs,
so this is purely additive.

The one type that may produce a tsc error is `Buffer` — Node 20 types made `Buffer` extend
`Uint8Array` more strictly. If any code treats a `Buffer` as a plain `Uint8Array` without
acknowledging the extended interface, tsc will flag it.

### 2.2 `package.json` change

```json
"@types/node": "^20.0.0"
```

### 2.3 Verification

```bash
corepack pnpm build
corepack pnpm test
```

---

## Recommended follow-up after this PR

Once TypeScript 5 is stable, consider:

- **`@typescript-eslint` 8.x** — The current `@typescript-eslint` 5.x does not understand all
  TS 5 syntax. This pairs with the ESLint 9 upgrade (see `ESLINT9_UPGRADE.md`).

- **`typescript-eslint` unified package** — TS-eslint 6+ consolidated into a single
  `typescript-eslint` package replacing the separate `@typescript-eslint/parser` +
  `@typescript-eslint/eslint-plugin`. Plan this as part of the ESLint 9 migration.

---

## Risk level

✅ **Low.** No known breaking changes apply to this codebase. If tsc surfaces errors after the
bump, they are pre-existing real type problems, not regressions introduced by the upgrade.
