import type { ComponentType, LazyExoticComponent } from 'react';
import type { LucideIcon } from 'lucide-react';
import type { EverestConfiguration } from '@/lib/globals';

export type Flags = EverestConfiguration;

export type ServerCategory = 'general' | 'data' | 'configuration' | 'extensions';
export type AdminCategory = 'general' | 'access' | 'developers' | 'modules' | 'management' | 'extensions';

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
