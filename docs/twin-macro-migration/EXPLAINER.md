# Why We're Migrating Away from twin.macro

## The Core Problem

This project currently uses [twin.macro](https://github.com/ben-bud/twin.macro) to write Tailwind utility classes inside styled-components templates. While this works, it forces **every single file** through a slow Babel compilation pipeline — even plain TypeScript files that never touch a styled component. The result is a build that is far slower than it should be.

---

## How the Current Pipeline Works

```
.tsx file
   │
   ├─ uses twin.macro?  ─YES─► Babel (slow)
   │                              │
   │                         babel-plugin-macros
   │                              │ expands tw`` into CSS-in-JS calls at compile time
   │                         babel-plugin-styled-components
   │                              │ adds displayName/fileName debug annotations
   │                              ▼
   │                         Compiled JS (large, annotated)
   │
   └─ no macros?  ──────────► Babel anyway (current config applies to ALL files)
```

Because `@vitejs/plugin-react` is configured globally with no `include` filter, **every `.tsx` file** pays the Babel tax, regardless of whether it uses a macro. 480 out of 731 source files (66%) never call `tw`` but still run through Babel.

### What Babel costs you

| Stage | Tool | Time per file | Notes |
|---|---|---|---|
| Parse + transform | `@babel/core` | ~4–12 ms | JS AST, plugin chain |
| Macro expansion | `babel-plugin-macros` | ~2–6 ms per macro call | Runs Tailwind's JIT resolver per class |
| styled annotation | `babel-plugin-styled-components` | ~1–3 ms | Injects `displayName`/`fileName` |
| **esbuild (alternative)** | `esbuild` | **~0.05–0.2 ms** | 20–80× faster |

On a 731-file project, that gap compounds significantly across every dev-server HMR reload and every production build.

---

## How twin.macro Works (and Why It's Expensive)

`twin.macro` is a **Babel macro** — a compile-time code transform. When you write:

```tsx
import tw from 'twin.macro';

const box = <div css={tw`flex items-center p-4 bg-slate-800`} />;
```

Babel expands it at compile time into:

```tsx
const box = (
  <div
    css={{
      display: 'flex',
      alignItems: 'center',
      padding: '1rem',
      '--tw-bg-opacity': '1',
      backgroundColor: 'rgb(30 41 59 / var(--tw-bg-opacity))',
    }}
  />
);
```

And when used in a `styled` template:

```tsx
const Card = styled.div`
  ${tw`rounded-lg border border-slate-700`}
`;
```

It expands to a full CSS string with every Tailwind property inlined. This means:

1. **Babel must run** — no way around it; macros are Babel-only
2. **Every `tw`` call invokes Tailwind's resolver** — finding config, resolving class names, generating CSS values
3. **styled-components** then processes the resulting CSS-in-JS at runtime, injecting `<style>` tags into the DOM on every render where styles change

---

## The Target: Plain Tailwind + esbuild

After migration, files will look like this:

```tsx
// Before (Babel required)
import tw from 'twin.macro';
const btn = <button css={tw`px-4 py-2 rounded bg-blue-600 text-white`} />;

// After (esbuild only)
const btn = <button className="px-4 py-2 rounded bg-blue-600 text-white" />;
```

And for components that genuinely need dynamic styles:

```tsx
// Before (styled-components + twin.macro)
import styled from 'styled-components';
import tw from 'twin.macro';

const Card = styled.div<{ $active: boolean }>`
  ${tw`rounded border border-slate-700 p-4`}
  ${props => props.$active && tw`border-blue-500 bg-slate-800`}
`;

// After (plain className with clsx, or CSS variables)
import clsx from 'clsx';

const Card = ({ active, children }: { active: boolean; children: React.ReactNode }) => (
  <div className={clsx('rounded border border-slate-700 p-4', active && 'border-blue-500 bg-slate-800')}>
    {children}
  </div>
);
```

---

## What Gets Removed

| Package | Why removed | Replacement |
|---|---|---|
| `twin.macro` | The macro itself | `className` strings / `clsx` |
| `babel-plugin-macros` | Only needed for twin.macro | Nothing — esbuild handles JSX natively |
| `babel-plugin-styled-components` | Adds debug metadata to SC components | Nothing — or keep if SC stays |
| `styled-components` | Runtime CSS-in-JS | Tailwind classes + CSS variables for dynamic values |
| `@types/styled-components` | Types for SC | Nothing |
| `styled-components-breakpoint` | SC breakpoint helper | Tailwind responsive prefixes (`md:`, `lg:`, etc.) |
| `@vitejs/plugin-react` (Babel mode) | Required for macros | `@vitejs/plugin-react` (esbuild mode, no `babel` key) |

---

## The Build Speed Improvement

### Current (all files through Babel)

```
731 files × ~8 ms average = ~5.8 seconds in Babel transforms alone
+ styled-components runtime CSS injection on every render
+ larger JS bundles (inlined CSS values instead of class names)
```

### After migration (esbuild only)

```
731 files × ~0.1 ms average = ~0.07 seconds in esbuild transforms
+ Tailwind CSS is generated once as a static .css file
+ no runtime style injection
+ smaller JS bundles (class strings are tiny vs inlined CSS objects)
```

**Expected improvement: 40–70% faster production builds, 60–85% faster HMR in dev.**

The exact numbers depend on hardware, but the order-of-magnitude difference between Babel and esbuild is well-established and documented in [Vite's own performance guide](https://vitejs.dev/guide/performance.html).

---

## What About Dynamic Styles?

The most common concern with removing styled-components is: "how do I handle props-driven styles?"

The answer is almost always one of:

| Pattern | Solution |
|---|---|
| Toggle a class on/off | `clsx('base-classes', condition && 'extra-class')` |
| Choose between N variants | `variantMap[variant]` — an object of class strings |
| Truly dynamic CSS values (colors from API, user-set values) | CSS custom properties (`style={{ '--color': value }}`) with a utility class that reads `var(--color)` |
| Animations with JS-controlled values | `style={{ transform: `translateX(${x}px)` }}` |

All of these work without Babel, without styled-components, and without any runtime CSS generation.

---

## Summary

| Dimension | twin.macro + styled-components | Plain Tailwind + esbuild |
|---|---|---|
| Build tool | Babel (slow, ~8 ms/file) | esbuild (fast, ~0.1 ms/file) |
| HMR speed | Slow (full Babel re-transform) | Near-instant |
| Runtime CSS | Injected dynamically at render | Static `.css` file, cached forever |
| Bundle size | Larger (CSS values inlined as JS objects) | Smaller (short class strings) |
| Browser dev tools | CSS lives in `<style>` tags with generated class names | CSS lives in a cached stylesheet |
| Debugging | `displayName` annotations from SC | Standard source maps |
| Tailwind IntelliSense | Partial (works for `tw`` but not inside styled templates) | Full (works everywhere) |
| Complexity | High (Babel + macros + SC runtime) | Low (esbuild + PostCSS) |
