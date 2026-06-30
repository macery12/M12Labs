import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import {
    resources,
    DEFAULT_NS,
    NAMESPACES,
    SUPPORTED_LOCALES,
    FALLBACK_LOCALE,
    type SupportedLocale,
} from './resources';

const STORAGE_KEY = 'm12labs.locale';

// Resolve the starting locale. Precedence:
//   1. an explicit user choice persisted in localStorage,
//   2. the backend's per-user language (window.PterodactylUser.language),
//   3. the panel default (window.SiteConfiguration.locale),
//   4. the fallback ('en').
// Anything not in SUPPORTED_LOCALES is ignored so we never boot into an empty
// catalog. This is the same Blade→JS handoff the rest of bootstrap uses.
function resolveInitialLocale(): SupportedLocale {
    const candidates = [
        safeStorageGet(),
        window.PterodactylUser?.language,
        window.SiteConfiguration?.locale,
    ];
    for (const c of candidates) {
        if (c && (SUPPORTED_LOCALES as readonly string[]).includes(c)) {
            return c as SupportedLocale;
        }
    }
    return FALLBACK_LOCALE;
}

function safeStorageGet(): string | null {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

const initialLocale = resolveInitialLocale();

void i18n.use(initReactI18next).init({
    resources,
    lng: initialLocale,
    fallbackLng: FALLBACK_LOCALE,
    ns: NAMESPACES as unknown as string[],
    defaultNS: DEFAULT_NS,
    interpolation: {
        // React already escapes output, so i18next must not double-escape.
        escapeValue: false,
    },
    returnNull: false,
});

// Keep <html lang> in sync for a11y + correct CSS :lang() / hyphenation.
document.documentElement.lang = initialLocale;
i18n.on('languageChanged', (lng) => {
    document.documentElement.lang = lng;
});

// Switch locale at runtime and remember the choice. Exposed for a future
// language picker; persists so the next visit boots straight into it.
export function setLocale(locale: SupportedLocale): Promise<unknown> {
    try {
        window.localStorage.setItem(STORAGE_KEY, locale);
    } catch {
        /* private mode / storage disabled — non-fatal */
    }
    return i18n.changeLanguage(locale);
}

export default i18n;
