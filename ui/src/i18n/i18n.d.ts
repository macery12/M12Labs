// Type-safety for translations. This augmentation teaches react-i18next the
// exact namespaces and keys that exist in the English catalog, so:
//   • t('actions.save') autocompletes and a typo like t('actions.svae') is a
//     compile error (`pnpm build` runs `tsc -b` and fails),
//   • a new page that references a key not yet in the catalog won't build —
//     this is what keeps the catalog and the UI from drifting apart.
//
// English is the source of truth for the key shape (see resources.ts).

import 'i18next';
import type { resources, DEFAULT_NS } from './resources';

declare module 'i18next' {
    interface CustomTypeOptions {
        defaultNS: typeof DEFAULT_NS;
        resources: (typeof resources)['en'];
    }
}
