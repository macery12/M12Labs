# Frontend Platform Plan (Vite 8 + Tailwind)

Canonical frontend plan for May 2026.

Current baseline:

- `vite`: `5.4.21`
- `tailwindcss`: `3.2.7`
- Node: `18.19.1`

Target baseline:

- `vite`: `^8`
- `tailwindcss`: either latest `3.4.x` (compat mode) or `4.x` (modern mode)
- Node: `20+` for full tool compatibility

---

## About `npx @tailwindcss/upgrade`

`npx @tailwindcss/upgrade` is the official Tailwind v3 -> v4 migration tool.

What it does for most projects:

- updates dependencies
- migrates config patterns where possible
- updates template usage for many known breaking changes

Important requirements and caveats:

- requires Node 20+
- run on a dedicated branch
- review all diffs manually
- run full visual QA (Tailwind v4 has real behavior changes)

Recommended command sequence:

```bash
# after switching to Node 20+
pnpm install
npx @tailwindcss/upgrade
pnpm build
pnpm test
```

---

## Vite 8 path (safe sequence)

Do not jump multiple major versions in one PR.

1. Vite 5 -> 6
2. Vite 6 -> 7
3. Vite 7 -> 8

For each phase:

```bash
pnpm up vite@^<major> @vitejs/plugin-react@latest laravel-vite-plugin@latest -D
pnpm build
pnpm test
```

Keep each major upgrade isolated so regressions are attributable.

---

## Tailwind strategy options

### Option A: Compatibility first

- upgrade `tailwindcss` to latest `3.4.x`
- keep existing PostCSS setup
- postpone v4 until backend/platform upgrades settle

Good when minimizing UI risk during framework migrations.

### Option B: Modernize now (v4)

- move to Node 20+
- run `npx @tailwindcss/upgrade`
- migrate to `@tailwindcss/postcss` or `@tailwindcss/vite` plugin pattern
- execute full UI smoke + screenshot checks

Good when frontend modernization is a current priority and browser support allows it.

---

## Known high-value package updates (simple wins)

Low risk / deprecation-focused:

- replace `@floating-ui/react-dom-interactions` -> `@floating-ui/react`
- move old `xterm` package namespace to `@xterm/*`
- update `tailwindcss` to latest 3.4.x at minimum
- update `swr` minor
- update `@testing-library/user-event` patch/minor

Medium complexity (own PR each):

- `@heroicons/react` v1 -> v2
- `@headlessui/react` v1 -> v2
- `axios` 0.x -> 1.x
- `chart.js` 3 -> 4

Large/risky (defer until platform stable):

- `react` 18 -> 19
- `react-router` 6 -> 7
- `styled-components` 5 -> 6

---

## Verify after every frontend PR

```bash
pnpm build
pnpm test
```

Also perform manual checks for:

- admin console and xterm views
- chart-heavy pages
- auth/account flow views
- tooltip/modal/dropdown interactions
