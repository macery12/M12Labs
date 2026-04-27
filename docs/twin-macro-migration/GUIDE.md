# twin.macro → Plain Tailwind Migration Guide

**Goal:** Fully remove `twin.macro`, `styled-components`, and Babel from the build pipeline so every file is compiled by esbuild. This eliminates the per-file Babel overhead (~8 ms/file × 731 files) and replaces runtime CSS-in-JS injection with a static Tailwind stylesheet.

See [`EXPLAINER.md`](./EXPLAINER.md) for the full rationale.

---

## Repository Context

| Metric | Value |
|---|---|
| Total source files | 731 (`.ts` / `.tsx`) |
| Files using `twin.macro` | 249 |
| Files using `styled-components` | 38 (36 also use twin) |
| Files already using pure `className` | 480 (66%) |
| Primary twin.macro patterns | `css={tw\`...\`}` (2169 usages), `${tw\`...\`}` in styled templates (251), `tw\`` as expression value (2563) |
| Special twin imports | `theme` (3 files), `css` (1 file), `TwStyle` type (1 file), `styled` re-exported (3 files) |

**Key files to understand before starting:**
- `vite.config.mts` — Vite + Babel plugin config
- `tailwind.config.js` — Tailwind theme (custom colors, fonts)
- `package.json` → `babelMacros.twin` — twin.macro preset config
- `resources/scripts/elements/` — shared UI primitives (highest reuse, refactor first)

---

## Phase 1 — Audit and Scaffolding

**Goal:** Set up the tooling and patterns that all later phases will follow. Do not touch any component files yet.

### Tasks

1. **Install `clsx`** — the utility for conditional className composition:
   ```
   pnpm add clsx
   ```
   Verify it is now in `package.json` dependencies.

2. **Add a `resolveConfig` helper** for reading Tailwind theme values in JS (replaces `theme()` from twin.macro):

   Create `resources/scripts/lib/tailwindTheme.ts`:
   ```ts
   import resolveConfig from 'tailwindcss/resolveConfig';
   import tailwindConfig from '../../../tailwind.config.js';

   const resolved = resolveConfig(tailwindConfig);
   export const twTheme = resolved.theme;
   ```

   Install the type if needed: `pnpm add -D @types/tailwindcss` (check if already present).

3. **Document the three conversion patterns** in a comment block at the top of a scratch file `/tmp/migration-patterns.md` (not committed). The three patterns are:

   | Pattern | Before | After |
   |---|---|---|
   | Inline css prop | `<div css={tw\`flex p-4\`}>` | `<div className="flex p-4">` |
   | Styled with tw interpolation | `styled.div\`${tw\`p-4\`}\`` | `className="p-4"` on the element, or a `const cls = 'p-4'` constant |
   | Conditional styled | `${props => props.$active && tw\`bg-blue-500\`}` | `clsx('base', active && 'bg-blue-500')` |

4. **Run the existing test suite** and record the baseline result so regressions can be caught:
   ```
   pnpm vitest run
   ```

### Done when
- `clsx` is in `package.json`
- `tailwindTheme.ts` helper exists
- Test suite passes at baseline

---

## Phase 2 — Refactor `elements/` (Shared Primitives)

**Goal:** Convert all 75 files in `resources/scripts/elements/` that use twin.macro. These are imported by hundreds of other files, so getting them right is critical.

### Approach

Work file by file. For each file:

1. Remove `import tw from 'twin.macro'` (and any other twin imports)
2. Remove `import styled from 'styled-components'` if it is used
3. Replace `styled.X\`...\`` components with a plain functional component using `className`
4. Replace `css={tw\`...\`}` props with `className="..."`
5. Replace `${tw\`...\`}` interpolations inside styled templates with the literal class string
6. For conditional styles, use `clsx`

### High-priority elements to convert first (most imported)

- `Spinner.tsx` — used on almost every page
- `Label.tsx` — used in all forms
- `Input.tsx` — used in all forms
- `Button/` — used everywhere
- `Modal.tsx` — widely used
- `GreyRowBox.tsx` — used in list views
- `PageContentBlock.tsx` — page layout wrapper

### Special cases to watch

- **`Spinner.tsx`** uses a CSS `keyframes` animation defined with `styled-components`. Replace with a Tailwind `animate-spin` class or a CSS module.
- **`Sidebar.tsx`** uses `tw, { css, styled }` — imports both `css` and `styled` from twin. The `css` helper generates a CSS object; replace its content with a plain string className.
- **`MessageBox.tsx`** uses `TwStyle` as a TypeScript type — replace with `string` or `React.CSSProperties` depending on usage.
- **`editor/Editor.tsx`** imports `TwStyle` as a type — same replacement as above.
- **`SearchableSelect.tsx`** uses `tw, { styled }` from twin — styled re-export means SC is pulled through twin; replace both.

### Done when
- Zero `from 'twin.macro'` imports remain in `resources/scripts/elements/`
- Zero `from 'styled-components'` imports remain in `resources/scripts/elements/`
- `pnpm vitest run` still passes

---

## Phase 3 — Refactor `components/account/`

**Goal:** Convert all files under `resources/scripts/components/account/` (approximately 35 files).

### Approach

Same as Phase 2. Most files in this directory use only `css={tw\`...\`}` — the simplest pattern to convert (mechanical find-and-replace of `css={tw\`X\`}` → `className="X"`).

### Files with more complex patterns

- `billing/order/NodeBox.tsx` — check for styled components with conditional props
- `ssh/` files — check for any dynamic class generation

### Done when
- Zero `from 'twin.macro'` imports in `components/account/`
- `pnpm vitest run` passes

---

## Phase 4 — Refactor `components/admin/`

**Goal:** Convert all files under `resources/scripts/components/admin/` (approximately 80 files).

### Approach

Same as Phase 2. The `SubNavigation.tsx` file imports `styled` directly from twin.macro (`import tw, { styled } from 'twin.macro'`) — this is a re-export of styled-components via twin. Convert it to import `styled` directly from `styled-components` first, then in a follow-up convert the styled usage to className.

### Special cases

- `SubNavigation.tsx` — uses both `tw` and `styled` from twin; also currently imports from `styled-components/macro` elsewhere. Consolidate to a plain className-based implementation.
- Any file using `theme()` from twin — replace with `twTheme` from the helper created in Phase 1.

### Done when
- Zero `from 'twin.macro'` imports in `components/admin/`
- `pnpm vitest run` passes

---

## Phase 5 — Refactor `components/server/`

**Goal:** Convert all files under `resources/scripts/components/server/` (the largest directory, approximately 130 files).

### Approach

Same pattern. This is the largest section and the one with the most complex usage.

### Special cases

**Console files** (`console/chart.ts`, `console/StatGraphs.tsx`, `console/Console.tsx`):
- These use `import { theme } from 'twin.macro'` to read Tailwind color tokens for Chart.js and xterm configurations.
- Replace with `twTheme.colors.slate[700]` etc. using the `tailwindTheme.ts` helper created in Phase 1.
- Example replacement:
  ```ts
  // Before
  import { theme } from 'twin.macro';
  color: theme('colors.slate.700')

  // After
  import { twTheme } from '@/lib/tailwindTheme';
  color: twTheme.colors.slate[700]
  ```

**ModList.tsx / ModDetails.tsx** — large files mixing `tw` and `styled.div\`...\``. The `styled` components with `${tw\`...\`}` interpolations are the main work here. Convert styled components to functions with className.

**Files using `css={[tw\`...\`, tw\`...\`]}`** (array css prop):
- Replace with `clsx('class1 class2', condition && 'class3')`

### Done when
- Zero `from 'twin.macro'` imports in `components/server/`
- `pnpm vitest run` passes

---

## Phase 6 — Refactor Remaining Files and `assets/`

**Goal:** Convert all remaining files not covered by Phases 2–5.

### Known remaining files

- `resources/scripts/assets/css/GlobalStylesheet.ts` — uses `tw` to build a global CSS template. This file injects base styles via styled-components' `createGlobalStyle`. Replace the `${tw\`...\`}` calls with literal CSS or move the reset styles into a plain `.css` file imported directly.
- Any remaining scattered files outside the main directories

### GlobalStylesheet approach

The global stylesheet pattern in twin.macro:
```ts
import tw from 'twin.macro';
import { createGlobalStyle } from 'styled-components';

export default createGlobalStyle`
  body {
    ${tw`font-sans text-neutral-200`}
  }
`;
```

Replace with a plain CSS file (`resources/scripts/assets/css/global.css`) and import it in `index.tsx`:
```css
/* global.css */
body {
  @apply font-sans text-neutral-200;
}
```

Or simply use explicit CSS properties without Tailwind's `@apply` if the values are straightforward.

### Done when
- Zero `from 'twin.macro'` imports anywhere in `resources/scripts/`
- `pnpm vitest run` passes

---

## Phase 7 — Remove styled-components

**Goal:** Remove the remaining 2 files that use `styled-components` without twin.macro (the pure SC files), then remove the dependency.

### Tasks

1. Find all remaining styled-components usage:
   ```
   grep -rln "from 'styled-components'" resources/scripts/
   ```
   At this point it should be only 2 files (plus any Phase 5/6 stragglers).

2. Convert them using the same className approach.

3. Remove from `package.json`:
   - `dependencies`: `styled-components`, `styled-components-breakpoint`
   - `devDependencies`: `@types/styled-components`, `babel-plugin-styled-components`

4. Remove the `babelMacros.styledComponents` block from `package.json`.

5. Remove `twin.macro` from `dependencies`.

6. Remove `babel-plugin-macros` from `devDependencies`.

7. Run `pnpm install` to update the lockfile.

### Done when
- Zero `from 'styled-components'` imports in `resources/scripts/`
- Zero `from 'twin.macro'` imports anywhere
- Removed packages are no longer in `package.json`
- `pnpm install` succeeds

---

## Phase 8 — Remove Babel from Vite Config

**Goal:** Update `vite.config.mts` to run `@vitejs/plugin-react` in pure esbuild mode (no Babel), and remove the `babelMacros` config from `package.json`.

### Tasks

1. **Update `vite.config.mts`**:

   Remove the `babel` key from the `react()` plugin call entirely:

   ```ts
   // Before
   react({
       babel: {
           plugins: ['babel-plugin-macros', 'babel-plugin-styled-components'],
       },
   })

   // After
   react()
   ```

   When no `babel` option is passed, `@vitejs/plugin-react` uses esbuild for all transforms — no Babel process is spawned.

2. **Remove the `babelMacros` block from `package.json`**:
   ```json
   // Remove this entire block:
   "babelMacros": {
     "twin": { ... },
     "styledComponents": { ... }
   }
   ```

3. **Run a full production build** to verify everything compiles:
   ```
   pnpm vite build
   ```
   Watch for any TypeScript errors or missing imports.

4. **Run `pnpm vitest run`** — all tests must pass.

5. **Manually test** in dev mode (`pnpm vite dev` or via artisan) that:
   - Styles render correctly
   - No console errors about missing CSS classes
   - Chart.js graphs show correct colors
   - Console terminal renders correctly

6. **Measure the build time** before and after and record it in a comment on this PR.

### Done when
- `vite.config.mts` has no `babel` key
- `package.json` has no `babelMacros` block
- `pnpm vite build` completes without errors
- `pnpm vitest run` passes
- Manual smoke test passes

---

## Cleanup Checklist (after all 8 phases)

Run these checks before closing the PR:

```bash
# No twin.macro imports
grep -rn "from 'twin.macro'" resources/scripts/

# No styled-components imports
grep -rn "from 'styled-components'" resources/scripts/

# No babel config key in vite
grep -n "babel" vite.config.mts

# No babelMacros in package.json
grep -n "babelMacros" package.json

# These packages should NOT be in package.json
grep -n "twin.macro\|babel-plugin-macros\|babel-plugin-styled-components\|styled-components" package.json

# Run full test suite
pnpm vitest run

# Run production build
pnpm vite build
```

---

## Rollback Strategy

Each phase is independently committed. If a phase introduces a regression:

1. Identify the bad commit with `git log --oneline`
2. `git revert <sha>` to undo just that phase
3. Investigate and re-apply with a fix

Phases 1–6 (component refactors) are independently reversible. Phase 7 (dependency removal) and Phase 8 (vite config) should only be merged after all component phases are complete and tested.

---

## Common Mistakes to Avoid

| Mistake | What goes wrong | Fix |
|---|---|---|
| Leaving a `css` prop on an element | React/preact does not apply a `css` prop as styles — it is silently ignored | Always convert `css={tw\`...\`}` to `className="..."` |
| Missing a class in the conversion | Styles break silently | Do a visual spot-check after each file |
| Removing `clsx` import when still needed | TypeScript error | Keep `clsx` import when conditional classes are present |
| Converting `${tw\`...\`}` to a literal but forgetting to check prop conditions | Conditional styles always apply or never apply | Carefully map each condition to `clsx(condition && 'class')` |
| Calling `twTheme.colors.slate[700]` when chart expects a hex string | Chart renders with `undefined` color | `twTheme.colors.slate[700]` returns a value like `"#334155"` — should work, but verify the Chart.js config accepts it |
| Deleting `@vitejs/plugin-react` instead of just removing the `babel` key | Build fails completely | Only remove the `babel: { plugins: [...] }` option, keep the `react()` call |
