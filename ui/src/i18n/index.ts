// Translation entrypoint for the V2 UI (Paraglide JS).
//
// Strings live in ../../messages/<locale>.json and are compiled to typed message
// functions under src/paraglide (see vite.config.ts / `pnpm build`). Components
// import { m } and call m['some.key']({ vars }); the key is the namespace-prefixed
// id (e.g. m['nav.topnav.admin']()). A typo is a compile error because each id is
// a real export — no module augmentation needed.
//
// Importing this module also installs the panel's locale resolution (below), so
// main.tsx imports it for its side effects before first render.

import { cloneElement, type ReactElement, type ReactNode } from 'react';
import * as messages from '@/paraglide/messages';
import {
    overwriteGetLocale,
    overwriteSetLocale,
    locales,
    baseLocale,
    type Locale,
} from '@/paraglide/runtime';

/** Compiled Paraglide message functions, keyed by namespace-prefixed id. */
export const m = messages;

type MessageFn = (inputs?: Record<string, unknown>) => string;

/**
 * Dynamic message lookup for ids built at runtime (server/power states, nav
 * labels, theme tokens, …) that can't be referenced statically as m['x']. Falls
 * back to `fallback`, then the id itself — mirroring the old i18next
 * t(key, { defaultValue }) behaviour.
 */
export function td(id: string, fallback?: string): string {
    const fn = (messages as unknown as Record<string, MessageFn | undefined>)[id];
    return fn ? fn() : fallback ?? id;
}

/**
 * Render a message that embeds simple paired tags — e.g.
 * "I agree to the <terms>Terms</terms>" — by replacing each `<tag>…</tag>` with
 * the matching React element (cloned with the inner text as its children). Text
 * outside the tags is kept as-is. This is the Paraglide stand-in for the rare
 * i18next `<Trans components={…} />` strings that carry links/markup; tags are
 * non-nesting, which covers every such string in the panel.
 */
export function formatTags(message: string, components: Record<string, ReactElement>): ReactNode[] {
    const nodes: ReactNode[] = [];
    const tag = /<(\w+)>([\s\S]*?)<\/\1>/g;
    let lastIndex = 0;
    let key = 0;
    let match: RegExpExecArray | null;
    while ((match = tag.exec(message))) {
        if (match.index > lastIndex) nodes.push(message.slice(lastIndex, match.index));
        const name = match[1] ?? '';
        const inner = match[2] ?? '';
        const element = components[name];
        nodes.push(element ? cloneElement(element, { key: key++ }, inner) : inner);
        lastIndex = tag.lastIndex;
    }
    if (lastIndex < message.length) nodes.push(message.slice(lastIndex));
    return nodes;
}

const STORAGE_KEY = 'm12labs.locale';

function isSupported(value: string | null | undefined): value is Locale {
    return !!value && (locales as readonly string[]).includes(value);
}

function safeStorageGet(): string | null {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

// Resolve the active locale. Precedence:
//   1. an explicit user choice persisted in localStorage,
//   2. the backend's per-user language (window.PterodactylUser.language),
//   3. the panel default (window.SiteConfiguration.locale),
//   4. the base locale ('en').
// Anything not in `locales` is ignored so we never boot into a missing catalog.
function resolveLocale(): Locale {
    const candidates = [
        safeStorageGet(),
        window.PterodactylUser?.language,
        window.SiteConfiguration?.locale,
    ];
    for (const c of candidates) {
        if (isSupported(c)) return c;
    }
    return baseLocale;
}

let currentLocale: Locale = resolveLocale();

// Drive Paraglide off our own resolution rather than its cookie/URL strategies,
// keeping the exact Blade→JS precedence the rest of bootstrap uses.
overwriteGetLocale(() => currentLocale);
overwriteSetLocale((locale) => {
    currentLocale = locale;
    try {
        window.localStorage.setItem(STORAGE_KEY, locale);
    } catch {
        /* private mode / storage disabled — non-fatal */
    }
    // Keep <html lang> in sync for a11y + correct CSS :lang() / hyphenation.
    document.documentElement.lang = locale;
});

document.documentElement.lang = currentLocale;

// Switch locale at runtime and remember the choice. Exposed for a future
// language picker; persists so the next visit boots straight into it. Routes
// through the (overwritten) Paraglide setter above.
export { setLocale } from '@/paraglide/runtime';
