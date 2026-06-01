# PNPM Dependency Audit

Date: 2026-06-01
Project: /var/www/m12labs

## Summary

- Security audit status: No known vulnerabilities found.
- Goal status for "100% up to date": Not complete.
- Direct dependencies/devDependencies behind latest: Yes (many).
- Deprecated packages in current tree: Yes.
- Broken peer dependencies (current strict check): None detected with `pnpm install --strict-peer-dependencies`.

## Deprecated Packages That Need Updates

### Direct dependencies/devDependencies

| Package | Installed | Status | Recommended |
|---|---:|---|---|
| eslint | 8.34.0 | Deprecated (unsupported) | Upgrade to latest 10.x and update config/plugins for breaking changes |
| rimraf | 3.0.2 | Deprecated (<4 unsupported) | Upgrade to latest 6.x |
| @fortawesome/react-fontawesome | 0.2.0 | Deprecated (0.2.x unsupported) | Upgrade to latest 3.x |

### Deprecated transitive packages observed previously

These were reported by pnpm during prior installs in this workspace and usually clear only after top-level upgrades force new trees:

- @humanwhocodes/config-array@0.11.14
- @humanwhocodes/object-schema@2.0.3
- glob@7.2.3
- inflight@1.0.6
- lodash.get@4.4.2
- rimraf@2.6.3
- string-similarity@4.0.4

## Outdated Direct Dependencies (Current vs Latest)

Generated via `pnpm outdated`.

| Package | Current | Latest |
|---|---:|---:|
| @codemirror/autocomplete | 6.20.0 | 6.20.2 |
| @codemirror/commands | 6.10.2 | 6.10.3 |
| @codemirror/lang-javascript | 6.2.4 | 6.2.5 |
| @codemirror/language | 6.12.2 | 6.12.3 |
| @codemirror/legacy-modes | 6.5.2 | 6.5.3 |
| @codemirror/lint | 6.9.4 | 6.9.6 |
| @types/debounce (dev) | 1.2.1 | 1.2.4 |
| @types/events (dev) | 3.0.0 | 3.0.3 |
| @types/styled-components (dev) | 5.1.26 | 5.1.36 |
| react-fast-compare | 3.2.0 | 3.2.2 |
| @codemirror/search | 6.6.0 | 6.7.0 |
| @codemirror/state | 6.5.4 | 6.6.0 |
| @codemirror/view | 6.39.15 | 6.43.0 |
| @lezer/common | 1.0.2 | 1.5.2 |
| @lezer/highlight | 1.1.3 | 1.2.3 |
| @testing-library/user-event (dev) | 14.4.3 | 14.6.1 |
| autoprefixer (dev) | 10.4.13 | 10.5.0 |
| babel-plugin-styled-components (dev) | 2.0.7 | 2.3.0 |
| classnames | 2.3.2 | 2.5.1 |
| eslint-plugin-react (dev) | 7.32.2 | 7.37.5 |
| formik | 2.2.9 | 2.4.9 |
| happy-dom (dev) | 20.8.9 | 20.9.0 |
| i18next-multiload-backend-adapter | 2.2.0 | 2.3.0 |
| nanoid | 5.0.9 | 5.1.11 |
| preact | 10.28.4 | 10.29.2 |
| react-select | 5.7.0 | 5.10.2 |
| strip-ansi | 7.1.2 | 7.2.0 |
| swr | 2.0.3 | 2.4.1 |
| @fortawesome/fontawesome-svg-core | 6.3.0 | 7.2.0 |
| @fortawesome/free-brands-svg-icons | 6.3.0 | 7.2.0 |
| @fortawesome/free-solid-svg-icons | 6.3.0 | 7.2.0 |
| @headlessui/react | 1.7.11 | 2.2.10 |
| @heroicons/react | 1.0.6 | 2.2.0 |
| @stripe/react-stripe-js | 3.10.0 | 6.5.0 |
| @stripe/stripe-js | 5.10.0 | 9.7.0 |
| @testing-library/dom (dev) | 9.0.0 | 10.4.1 |
| @testing-library/react (dev) | 14.0.0 | 16.3.2 |
| @types/node (dev) | 20.19.39 | 25.9.1 |
| @types/react (dev) | 18.0.28 | 19.2.16 |
| @types/react-dom (dev) | 18.0.11 | 19.2.3 |
| @typescript-eslint/eslint-plugin (dev) | 5.53.0 | 8.60.1 |
| @typescript-eslint/parser (dev) | 5.53.0 | 8.60.1 |
| boring-avatars | 1.7.0 | 2.0.4 |
| chart.js | 3.9.1 | 4.5.1 |
| copy-to-clipboard | 3.3.3 | 4.0.2 |
| cross-env (dev) | 7.0.3 | 10.1.0 |
| date-fns | 2.29.3 | 4.4.0 |
| debounce | 1.2.1 | 3.0.0 |
| deepmerge-ts | 4.3.0 | 7.1.5 |
| easy-peasy | 5.2.0 | 6.1.1 |
| eslint (dev) | 8.34.0 | 10.4.1 |
| eslint-config-prettier (dev) | 8.6.0 | 10.1.8 |
| eslint-plugin-prettier (dev) | 4.2.1 | 5.5.6 |
| eslint-plugin-react-hooks (dev) | 4.6.0 | 7.1.1 |
| framer-motion | 9.1.6 | 12.40.0 |
| i18next | 24.1.2 | 26.3.0 |
| pathe (dev) | 1.1.0 | 2.0.3 |
| postcss-import (dev) | 15.1.0 | 16.1.1 |
| postcss-nesting (dev) | 11.2.1 | 14.0.0 |
| postcss-preset-env (dev) | 8.0.1 | 11.3.0 |
| prettier (dev) | 2.8.4 | 3.8.3 |
| qrcode.react | 3.1.0 | 4.2.0 |
| react | 18.2.0 | 19.2.7 |
| react-chartjs-2 | 4.3.1 | 5.3.1 |
| react-dom | 18.2.0 | 19.2.7 |
| react-i18next | 12.2.0 | 17.0.8 |
| react-router | 6.30.2 | 7.16.0 |
| react-router-dom | 6.30.2 | 7.16.0 |
| rimraf (dev) | 3.0.2 | 6.1.3 |
| styled-components | 5.3.6 | 6.4.2 |
| tailwindcss (dev) | 3.2.7 | 4.3.0 |
| ts-essentials (dev) | 9.3.0 | 10.2.0 |
| twin.macro (dev) | 2.8.2 | 3.4.1 |
| typescript (dev) | 5.9.3 | 6.0.3 |
| @fortawesome/react-fontawesome | 0.2.0 | 3.3.1 |
| @tailwindcss/forms (dev) | 0.5.3 | 0.5.11 |
| @tailwindcss/line-clamp (dev) | 0.4.2 | 0.4.4 |
| @types/yup (dev) | 0.29.14 | 0.32.0 |
| prettier-plugin-tailwindcss (dev) | 0.2.3 | 0.8.0 |
| yup | 0.29.3 | 1.7.1 |

## High-Impact Major Upgrade Buckets (Likely Breaking)

Prioritize and test these carefully:

- Runtime stack: react 18 -> 19, react-dom 18 -> 19, react-router 6 -> 7.
- UI ecosystem: @headlessui/react 1 -> 2, @heroicons/react 1 -> 2, styled-components 5 -> 6, framer-motion 9 -> 12.
- Build tooling: tailwindcss 3 -> 4, postcss-* major bumps, prettier 2 -> 3, eslint 8 -> 10, @typescript-eslint 5 -> 8, typescript 5 -> 6.
- Data/validation: yup 0.x -> 1.x.
- Billing SDKs: @stripe/react-stripe-js 3 -> 6, @stripe/stripe-js 5 -> 9.

## Suggested Path To 100% Up To Date

1. Create a dependency-upgrade branch.
2. Run a controlled full bump:
   - `pnpm up --latest`
3. Reinstall and verify:
   - `pnpm install`
   - `pnpm lint`
   - `pnpm test`
   - `pnpm build`
4. Fix breakages by bucket (tooling first, then runtime/UI).
5. Re-run:
   - `pnpm outdated`
   - `pnpm audit`
6. Goal condition:
   - No rows in `pnpm outdated`
   - No vulnerabilities in `pnpm audit`
   - Lint/tests/build all green

## Notes

- The current package set is security-patched, but not latest.
- Some pinned `pnpm.overrides` were added for vulnerability remediation and may be reduced later once full major upgrades are complete.
