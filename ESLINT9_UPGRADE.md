# ESLint 9 Migration Guide

Migration from ESLint 8 (`.eslintrc.js` legacy config) to ESLint 9 (flat config,
`eslint.config.js`).

---

## Current state

| Package | Current | Target |
|---|---|---|
| `eslint` | 8.34.0 | `^9.0.0` |
| `@typescript-eslint/eslint-plugin` | 5.53.0 | `^8.0.0` |
| `@typescript-eslint/parser` | 5.53.0 | `^8.0.0` (absorbed into `typescript-eslint`) |
| `eslint-config-prettier` | 8.6.0 | `^9.0.0` |
| `eslint-plugin-prettier` | 4.2.1 | `^5.0.0` |
| `eslint-plugin-react` | 7.32.2 | `^7.37.0` (flat-config compatible) |
| `eslint-plugin-react-hooks` | 4.6.0 | `^5.0.0` |
| `eslint-plugin-node` | 11.1.0 | **Replace with `eslint-plugin-n@^17.0.0`** |

---

## Why ESLint 9 is a bigger change

ESLint 9 completely removes the legacy config format (`.eslintrc.js`, `.eslintrc.json`, etc.)
in favour of **flat config** (`eslint.config.js`). This is not a package bump — it requires
rewriting the configuration file.

`eslint-plugin-node` is archived and does not support flat config. Its actively maintained fork
is `eslint-plugin-n`.

`@typescript-eslint` 5.x and 6.x are not compatible with ESLint 9. The recommended path is to
upgrade to the unified `typescript-eslint` package at `^8.0.0`, which ships a flat-config-ready
helper.

---

## Phase 1 — Package changes

### 1.1 Remove

```
eslint-plugin-node
@typescript-eslint/eslint-plugin
@typescript-eslint/parser
eslint-config-prettier (absorb into flat config directly)
```

### 1.2 Add / bump

```json
"eslint": "^9.0.0",
"typescript-eslint": "^8.0.0",
"eslint-plugin-react": "^7.37.0",
"eslint-plugin-react-hooks": "^5.0.0",
"eslint-plugin-prettier": "^5.0.0",
"eslint-plugin-n": "^17.0.0"
```

> `eslint-config-prettier` still exists as a flat-config-compatible package but its exports are
> consumed differently in flat config — no longer `extends`-based. Include it via
> `...require('eslint-config-prettier')` or use `prettier.recommended`.

---

## Phase 2 — Replace `.eslintrc.js` with `eslint.config.js`

Delete `.eslintrc.js` entirely and create `eslint.config.js` at the repo root.

The equivalent of the current config in flat format:

```js
// eslint.config.js
import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import reactPlugin from 'eslint-plugin-react';
import reactHooksPlugin from 'eslint-plugin-react-hooks';
import prettierPlugin from 'eslint-plugin-prettier';
import prettierConfig from 'eslint-config-prettier';
import nPlugin from 'eslint-plugin-n';

export default tseslint.config(
    // Ignore patterns (replaces .eslintignore)
    {
        ignores: ['public/**', 'vendor/**', 'node_modules/**', 'bootstrap/cache/**'],
    },

    // Base recommended rules
    js.configs.recommended,
    ...tseslint.configs.recommended,

    // Main config block
    {
        files: ['resources/scripts/**/*.{ts,tsx}'],
        plugins: {
            react: reactPlugin,
            'react-hooks': reactHooksPlugin,
            prettier: prettierPlugin,
            n: nPlugin,
        },
        languageOptions: {
            parserOptions: {
                ecmaVersion: 6,
                ecmaFeatures: { jsx: true },
                project: './tsconfig.json',
                tsconfigRootDir: import.meta.dirname,
            },
            globals: {
                window: 'readonly',
                document: 'readonly',
                console: 'readonly',
            },
        },
        settings: {
            react: {
                pragma: 'React',
                version: 'detect',
            },
            linkComponents: [
                { name: 'Link', linkAttribute: 'to' },
                { name: 'NavLink', linkAttribute: 'to' },
            ],
        },
        rules: {
            ...reactPlugin.configs.recommended.rules,
            ...reactPlugin.configs['jsx-runtime'].rules,
            ...reactHooksPlugin.configs.recommended.rules,
            ...prettierConfig.rules,
            eqeqeq: 'error',
            'prettier/prettier': ['error', {}, { usePrettierrc: true }],
            'react/prop-types': 0,
            'react/display-name': 0,
            'react/no-unknown-property': ['error', { ignore: ['css'] }],
            '@typescript-eslint/no-explicit-any': 0,
            '@typescript-eslint/no-non-null-assertion': 0,
            'no-use-before-define': 0,
            '@typescript-eslint/no-use-before-define': 'warn',
            '@typescript-eslint/no-unused-vars': [
                'warn',
                { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
            ],
            '@typescript-eslint/ban-ts-comment': [
                'error',
                { 'ts-expect-error': 'allow-with-description' },
            ],
        },
    },

    // vite config file (runs in Node, not browser)
    {
        files: ['vite.config.mts'],
        ...tseslint.configs.disableTypeChecked,
    },
);
```

> **Note on `eslint.config.js` format:** The file must use ESM (`export default`) because
> `"type": "module"` is implied by the flat config format when the file has a `.js` extension.
> If the repo does not set `"type": "module"` in `package.json`, name the file
> `eslint.config.mjs` instead.
>
> Check `package.json`: this repo does **not** set `"type": "module"`, so use
> `eslint.config.mjs`.

---

## Phase 3 — Update `.eslintignore`

ESLint 9 flat config uses `ignores` arrays inside `eslint.config.js` (shown above).
The `.eslintignore` file is no longer read by ESLint 9. Move all ignore patterns into the
`ignores` array in the config and delete `.eslintignore`.

Check whether `.eslintignore` exists:

```bash
cat .eslintignore
```

---

## Phase 4 — Update `lint` script

The current lint script uses `--ext .ts,.tsx` which is a legacy flag removed in ESLint 9.
In flat config, file extensions are controlled by `files` globs inside the config.

**Current:**
```json
"lint": "eslint ./resources/scripts/**/*.{ts,tsx} --ext .ts,.tsx"
```

**After:**
```json
"lint": "eslint"
```

ESLint 9 will pick up `eslint.config.mjs` automatically and lint only the files/patterns
declared in `files` globs within that config.

---

## Phase 5 — `@typescript-eslint` v8 rule renames

Some rules were renamed between v5 and v8. The rules used in this repo:

| v5 rule | v8 rule | Status |
|---|---|---|
| `@typescript-eslint/no-explicit-any` | unchanged | ✅ |
| `@typescript-eslint/no-non-null-assertion` | unchanged | ✅ |
| `@typescript-eslint/no-use-before-define` | unchanged | ✅ |
| `@typescript-eslint/no-unused-vars` | unchanged | ✅ |
| `@typescript-eslint/ban-ts-comment` | `@typescript-eslint/ban-ts-comment` still exists but `ban-types` was split | Check |

Run `eslint --print-config resources/scripts/index.tsx` after the migration to confirm all rules
resolve.

---

## Verification

```bash
corepack pnpm install --no-frozen-lockfile
corepack pnpm lint
corepack pnpm build
corepack pnpm test
```

Expected: same lint warnings/errors as before (no new failures from rule changes).

---

## Risk level

⚠️ **Medium.** The package changes are mechanical but the config rewrite requires care. The
`eslint-plugin-node` → `eslint-plugin-n` switch may change which node-related rules fire.
Recommend running lint on the full codebase and reviewing output before merging.

Do this PR **after** the TypeScript 5 upgrade is merged, since `typescript-eslint` 8.x requires
TypeScript 4.7+ (satisfied) and works best with TS 5.x.
