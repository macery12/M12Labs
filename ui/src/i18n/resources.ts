// Static resource bundle for the V2 UI. Each feature owns a namespace (a JSON
// file under locales/<lng>/<namespace>.json). English is the source of truth —
// every key must exist here first; other locales are added per-namespace later
// (and can be lazy-loaded once the catalog grows).
//
// When you add a namespace: create locales/en/<ns>.json, import it here, add it
// to `en` below and to NAMESPACES. The CustomTypeOptions augmentation in
// i18n.d.ts then makes its keys type-checked everywhere `t()` is used.

import common from './locales/en/common.json';
import nav from './locales/en/nav.json';
import auth from './locales/en/auth.json';
import landing from './locales/en/landing.json';
import dashboard from './locales/en/dashboard.json';
import server from './locales/en/server.json';
import admin from './locales/en/admin.json';
import extensions from './locales/en/extensions.json';
import billing from './locales/en/billing.json';

export const resources = {
    en: {
        common,
        nav,
        auth,
        landing,
        dashboard,
        server,
        admin,
        extensions,
        billing,
    },
} as const;

// All namespaces, in load order. `common` is the default namespace so shared
// keys (actions, states, nav) can be referenced bare, e.g. t('actions.save').
export const NAMESPACES = ['common', 'nav', 'auth', 'landing', 'dashboard', 'server', 'admin', 'extensions', 'billing'] as const;

export const DEFAULT_NS = 'common' as const;

// The locales the panel ships. Add a code here when its catalog is complete.
// Each must map to a translation-management language in Crowdin.
export const SUPPORTED_LOCALES = ['en'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

export const FALLBACK_LOCALE: SupportedLocale = 'en';
