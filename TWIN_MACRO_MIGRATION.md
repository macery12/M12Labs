# twin.macro → Tailwind + CSS-in-JS Migration Guide

This document explains **why** `twin.macro` is the single biggest build-time bottleneck and lays
out a concrete, phased plan to remove it entirely.

---

## The Problem

### What twin.macro does

`twin.macro` is a Babel macro that lets you write Tailwind utility names inside tagged template
literals in styled-components:

```tsx
import tw, { styled } from 'twin.macro';

// tw`` template literal → expanded to a styled-components CSS block at compile time
const Button = tw.button`bg-blue-500 text-white px-4 py-2 rounded`;

// Or inline on a styled component
const Card = styled.div`
    ${tw`bg-zinc-800 p-4 rounded-lg`}
    border: 1px solid ${({ theme }) => theme.borderColor};
`;
```

At build time, each `tw\`` call is expanded into a full CSS string by looking up every utility
class name in the Tailwind config. This happens through Babel, not esbuild.

### Why it's slow

**Normal Vite + React build path (fast):**
```
.tsx → esbuild (strips types, transforms JSX) → Rollup (bundles)
```

**This project's build path with twin.macro (slow):**
```
.tsx → @babel/core (full AST parse + plugin chain) → esbuild → Rollup
```

Because `babel-plugin-macros` is passed to `@vitejs/plugin-react`'s `babel` option (in
`vite.config.mts` lines 8–12), every single file is forced through `@babel/core`:

```ts
// vite.config.mts
react({
    babel: {
        plugins: ['babel-plugin-macros', 'babel-plugin-styled-components'],
    },
})
```

There is no per-file opt-in — when **any** Babel plugin is provided here, **all 745 source files**
are parsed by `@babel/core`. Only 247 of them actually use `twin.macro`.

**Estimated overhead: 8–20 seconds per build.**

### Plugin chain detail

Three Babel plugins run on every file:

| Plugin | Purpose |
|---|---|
| `babel-plugin-macros` | Processes macro calls at compile time (entry point for `twin.macro`) |
| `babel-plugin-twin` | Wires `twin.macro`'s `tw` tag through the macro system |
| `babel-plugin-styled-components` | Adds display names, component IDs, and `pure` annotations to styled calls |

`babel-plugin-macros` is what causes the full Babel transform. It must run on every file because it
has no way of knowing which files contain macro calls without parsing them all.

---

## Migration Strategy

The goal is to write the same styles without `twin.macro` — using either plain Tailwind `className`
props or a thin wrapper around styled-components that doesn't need Babel macros.

There are **three levels** of usage in this codebase, each with a different migration path.

---

### Level 1 — Simple `tw\`` template tags on HTML elements (easiest)

**Before:**
```tsx
import tw from 'twin.macro';

const Button = tw.button`bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600`;
```

**After:** just use `className` directly. No library needed.
```tsx
const Button = ({ children, ...rest }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
    <button className="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600" {...rest}>
        {children}
    </button>
);
```

Or keep a thin styled wrapper without the macro:
```tsx
import styled from 'styled-components';

const Button = styled.button.attrs({
    className: 'bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600',
})``;
```

---

### Level 2 — `tw\`` inside a styled-components template (common pattern)

**Before:**
```tsx
import tw, { styled } from 'twin.macro';

const Panel = styled.div`
    ${tw`bg-zinc-800 rounded-lg p-6`}
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
`;
```

**After:** Use styled-components normally, replace `tw\`` with the actual CSS property values.
You can look up the generated CSS for any Tailwind class at https://tailwindcss.com/docs or run
`npx tailwindcss --content '' --output /dev/stdout` to see the output.

```tsx
import styled from 'styled-components';

const Panel = styled.div`
    background-color: rgb(39 39 42); /* zinc-800 */
    border-radius: 0.5rem;           /* rounded-lg */
    padding: 1.5rem;                 /* p-6 */
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
`;
```

Alternatively, move the Tailwind classes to a `className` prop and keep only the non-Tailwind CSS
in the styled block:
```tsx
import styled from 'styled-components';
import classNames from 'classnames';

const PanelBase = styled.div`
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.4);
`;

// Usage
<PanelBase className="bg-zinc-800 rounded-lg p-6" />
```

---

### Level 3 — `tw\`` with dynamic values / `theme()` calls

**Before:**
```tsx
import tw, { theme } from 'twin.macro';

const Badge = styled.span<{ active: boolean }>`
    ${({ active }) => active ? tw`bg-green-500 text-white` : tw`bg-zinc-700 text-zinc-400`}
    font-size: ${theme`fontSize.xs`};
`;
```

**After:** Use a conditional className or CSS custom property approach.
```tsx
import styled from 'styled-components';
import classNames from 'classnames';

// Option A: move the condition to className
const Badge = ({ active, children }: { active: boolean; children: React.ReactNode }) => (
    <span className={classNames(
        'text-xs',
        active ? 'bg-green-500 text-white' : 'bg-zinc-700 text-zinc-400'
    )}>
        {children}
    </span>
);

// Option B: data attribute + CSS (zero runtime cost)
const Badge = styled.span`
    font-size: 0.75rem;
    &[data-active='true'] { background-color: rgb(34 197 94); color: white; }
    &[data-active='false'] { background-color: rgb(63 63 70); color: rgb(161 161 170); }
`;
// <Badge data-active={String(active)}>
```

---

## Files to Migrate

Running `grep -rl "twin.macro" resources/scripts` gives **~247 files**. The breakdown by type:

| Pattern | Approx. count | Approach |
|---|---|---|
| `const X = tw.tag\`...\`` (standalone tw component) | ~60 | Replace with `className` on plain element |
| `${tw\`...\`}` inside a styled template | ~120 | Extract to className prop or literal CSS values |
| `tw\`` in JSX `css` prop | ~40 | Move to `className` prop |
| `theme\`...\`` / `css\`` helpers | ~27 | Inline CSS values or use CSS custom properties |

---

## Phased Migration Plan

### Phase A — Remove `babel-plugin-macros` from the Vite fast path (2–4 hours)

This is the highest-ROI single change. The approach: scope Babel to only files that contain macro
imports, instead of running it on everything.

1. Create a small Vite plugin that reads each file's imports and only applies Babel to files
   that contain `from 'twin.macro'`:

```ts
// vite-plugin-selective-babel.ts  (add to vite.config.mts plugins)
import type { Plugin } from 'vite';
import { transformWithBabel } from '@vitejs/plugin-react'; // internal helper

export function selectiveBabel(): Plugin {
    return {
        name: 'selective-babel',
        transform(code, id) {
            if (!id.includes('node_modules') && code.includes("'twin.macro'")) {
                // only run Babel on files that actually import twin.macro
                return transformWithBabel(code, id, {
                    plugins: ['babel-plugin-macros', 'babel-plugin-styled-components'],
                });
            }
        },
    };
}
```

> **Note:** `@vitejs/plugin-react` doesn't expose `transformWithBabel` publicly; the actual
> implementation needs to replicate the transform using `@babel/core` directly. The core idea
> is correct — filter by file content before passing to `@babel/core`.

2. Change `vite.config.mts` to remove the `babel` option from the `react()` plugin and instead
   use the selective plugin for files that need it.

**Estimated time save: 6–15 seconds** (Babel no longer runs on ~500 files that don't use macros).

### Phase B — Migrate the 247 files away from twin.macro (1–3 days)

Work file-by-file. Suggested order:

1. **Start with `tw.tag\`...\`` components** (Level 1 above) — the simplest to convert.
   Find them all: `grep -rn "^const .* = tw\." resources/scripts --include="*.tsx"`

2. **Convert `${tw\`...\`}` inside styled templates** (Level 2) — copy the CSS values directly.
   Find them: `grep -rn "\\${\s*tw\`" resources/scripts --include="*.tsx"`

3. **Convert conditional `tw\`` (Level 3)** — move logic to `classNames()` or data attributes.
   These are the most involved but there are only ~27.

### Phase C — Remove twin.macro and its Babel plugins entirely

Once all files are migrated:

1. Remove from `package.json`:
   ```json
   // devDependencies — remove these:
   "babel-plugin-macros": "...",
   "babel-plugin-styled-components": "...",
   "babel-plugin-twin": "...",
   "twin.macro": "..."
   ```

2. Remove the `babelMacros` configuration block from `package.json` (lines 148–158).

3. Remove the `babel` option from `react()` in `vite.config.mts`:
   ```ts
   // Before:
   react({
       babel: {
           plugins: ['babel-plugin-macros', 'babel-plugin-styled-components'],
       },
   })

   // After:
   react()
   ```

4. Remove `typescript-plugin-tw-template` from `tsconfig.json` plugins (it provides editor
   completions for `tw\`` — no longer needed once twin.macro is gone).

5. Run `pnpm install` to remove the packages from `node_modules`.

---

## What You Get After Full Migration

| Metric | Before | After |
|---|---|---|
| Build time (estimated) | ~30s | ~10–15s |
| Files that hit `@babel/core` | 745 | 0 |
| Babel plugins needed | 3 | 0 |
| `vendor-styled` chunk size | larger (styled-components v5 + twin runtime) | same (styled-components only, no macro overhead) |

The styled-components runtime itself remains — that's a separate, larger migration to v6 or away
from CSS-in-JS entirely. This migration only eliminates the **build-time** cost of the Babel macro
layer.

---

## Risk Assessment

| Risk | Likelihood | Mitigation |
|---|---|---|
| Visual regression on a component | Medium | Twin generates exact Tailwind CSS — the output CSS values are known and deterministic |
| `theme()` helper values are wrong | Low | Check Tailwind config for custom scale values before hardcoding |
| TypeScript errors after removing twin types | Low | Remove `macros.d.ts` reference; `tw\`` type errors disappear with the import |
| styled-components still needs Babel plugin for display names | Zero | `babel-plugin-styled-components` is only needed for dev display names — production builds work fine without it |

---

## Tooling Tip

Use [Tailwind CSS IntelliSense](https://marketplace.visualstudio.com/items?itemName=bradlc.vscode-tailwindcss)
in VS Code to get autocomplete on `className` strings. It's strictly better than
`typescript-plugin-tw-template` for pure className usage and requires zero build configuration.
