import type { ComponentType, LazyExoticComponent } from 'react';
import type { LucideIcon } from 'lucide-react';
import type { EverestConfiguration } from '@/lib/globals';

export type Flags = EverestConfiguration;

export type ServerCategory = 'general' | 'data' | 'configuration' | 'extensions';
// Admin groups, in the order the sidebar shows them: extensions first, then
// the rest ordered by how often an admin reaches for them, so the rarely used
// System tools sit last (and start collapsed). Overview has no category and
// renders above every group without a header.
export type AdminCategory = 'extensions' | 'operations' | 'storefront' | 'access' | 'configuration' | 'system';

export interface RouteDef {
    /** Path relative to the area mount (e.g. '', 'credentials', 'files/*'). */
    path: string;
    /** Nav label. Routes without a name are reachable but hidden from nav. */
    name?: string;
    /**
     * Message id resolved at render time, taking precedence over `name`.
     *
     * Core routes carry English literals in `name` and the sidebar looks them
     * up under `nav.items.<name>`. Extension pages have no such core key: their
     * label lives in the package's own `ext.<id>.*` catalog. Resolving that id
     * where the route is declared would run at module scope, before the locale
     * catalog is loaded, and bake in the fallback — so the id travels to the
     * sidebar and is resolved there instead, which also makes these labels
     * follow a locale switch.
     */
    labelKey?: string;
    icon?: LucideIcon;
    /** Sidebar grouping (server + admin areas). */
    category?: ServerCategory | AdminCategory;
    /**
     * The installed extension this page belongs to. `buildNav` folds every
     * page sharing an id under one parent entry named after the extension, so
     * a package with several admin pages reads as one thing in the sidebar
     * rather than as unrelated rows. `name` is the manifest name, rendered
     * verbatim (manifest copy is not catalogued); absent in page manifests the
     * panel wrote before it carried one. `labelKey` is the package's own
     * catalogued label for that entry (manifest `capabilities.nav.admin`),
     * preferred over the name when declared.
     */
    extension?: { id: string; name?: string; labelKey?: string; icon?: LucideIcon };
    /** Dotted permission(s) required to see/visit this route. */
    permission?: string | string[];
    /** Feature-flag gate; hidden when it returns false. */
    condition?: (flags: Flags) => boolean;
    /** Exact-match route (React Router `index`/`end`). */
    end?: boolean;
    /** The page component. Every registry entry has one. */
    element: LazyExoticComponent<ComponentType> | ComponentType;
}

/** Small helper mirroring V1's route() so entries read declaratively. */
export function route(path: string, opts: Omit<RouteDef, 'path'>): RouteDef {
    return { path, ...opts };
}
