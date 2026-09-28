import { baseLocale, type Locale } from '@/paraglide/runtime';
import { appCatalogLoaders, catalogLoaders, publicCatalogLoaders, type RuntimeCatalog } from './generated/catalogLoaders';
import type { MessageFunctions } from './generated/messageTypes';

type MessageFunction = (inputs?: Record<string, unknown>) => string;

let activeCatalog: RuntimeCatalog | null = null;
const resolvedFunctions = new Map<string, MessageFunction>();

/** Convert a dotted message id to Paraglide's generated locale export name. */
export function compiledMessageName(id: string): string {
    const uppercaseCount = id.match(/[A-Z]/g)?.length ?? 0;
    const normalized = id.replace(/[^A-Za-z0-9_$]/g, '_').toLowerCase();
    return uppercaseCount === 0 ? normalized : `${normalized}${uppercaseCount}`;
}

/**
 * Stable, typed message facade backed directly by the active Paraglide module.
 * Resolving properties on demand removes the generated id-map wrapper and the
 * startup copy of every catalog function while preserving synchronous m.foo().
 */
export const m = new Proxy(Object.create(null) as MessageFunctions, {
    get(_target, property) {
        if (typeof property !== 'string') return undefined;

        const cached = resolvedFunctions.get(property);
        if (cached) return cached;

        const message = activeCatalog?.[compiledMessageName(property)];
        if (message) resolvedFunctions.set(property, message);
        return message;
    },
});

let loadedLocale: Locale | null = null;
let loadedScope: CatalogScope | null = null;
let loadSequence = 0;

/**
 * Nested catalog tiers: `public` (guest shells) ⊂ `app` (signed-in player UI)
 * ⊂ `full` (everything, including the admin area's copy). Loading a tier
 * already covered by the active one is a no-op.
 */
export type CatalogScope = 'public' | 'app' | 'full';

const scopeRank: Record<CatalogScope, number> = { public: 0, app: 1, full: 2 };

/** Whether the active catalog already covers a tier in this locale. */
export function catalogCovers(locale: Locale, scope: CatalogScope): boolean {
    return loadedLocale === locale && loadedScope !== null && scopeRank[loadedScope] >= scopeRank[scope];
}

/** The tier currently loaded, so a locale switch can keep it. */
export function loadedCatalogScope(): CatalogScope | null {
    return loadedScope;
}

/** Load exactly one compiled locale and atomically replace the active catalog. */
export async function initializeMessages(locale: Locale, scope: CatalogScope = 'full'): Promise<void> {
    if (catalogCovers(locale, scope)) return;

    const sequence = loadSequence++;
    const startMark = `m12:i18n:${locale}:${sequence}:start`;
    const endMark = `m12:i18n:${locale}:${sequence}:end`;
    performance.mark(startMark, { detail: { locale } });
    const loaders = scope === 'public' ? publicCatalogLoaders : scope === 'app' ? appCatalogLoaders : catalogLoaders;
    const loader = loaders[locale] ?? loaders[baseLocale];
    const catalog = await loader();
    activeCatalog = catalog;
    resolvedFunctions.clear();
    loadedLocale = locale;
    loadedScope = scope;
    performance.mark(endMark, { detail: { locale, scope, messages: Object.keys(catalog).length } });
    performance.measure(`m12:i18n:${locale}`, startMark, endMark);
}

/** Resolve a finite message id assembled at runtime. */
export function td(id: string, fallback?: string): string {
    const fn = (m as unknown as RuntimeCatalog)[id];
    return fn ? fn() : fallback ?? id;
}

/**
 * td() for a message that takes inputs.
 *
 * The typed `m` surface is the right way to reach a message the panel ships,
 * because it checks the inputs at compile time. This exists for ids assembled
 * at runtime — extension packages, whose keys are not in the catalog when the
 * package is written. The fallback is interpolated the same way so an untranslated
 * locale still reads correctly rather than showing raw `{placeholders}`.
 */
export function tdi(id: string, fallback: string, inputs: Record<string, unknown>): string {
    const fn = (m as unknown as Record<string, ((inputs: Record<string, unknown>) => string) | undefined>)[id];
    if (fn) return fn(inputs);

    return fallback.replace(/\{(\w+)\}/g, (match, key) => (key in inputs ? String(inputs[key]) : match));
}
