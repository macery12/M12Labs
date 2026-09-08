/// <reference types="vite/client" />
import { lazy, type ComponentType } from 'react';
import { withExtensionIsolation } from '@/extensions-sdk/ExtensionErrorBoundary';
import { route, type RouteDef } from './registry';
import { resolveExtensionIcon } from '@/pages/admin/extensions/extMeta';

// Admin pages contributed by installed extension packages. Mirrors the server
// registry (pages/server/extensions/registry.ts): a package opts in by adding
// an `admin` block to its meta.json and shipping an admin.tsx default-exporting
// the page. The separate entry file keeps server-only extensions from ever
// loading admin code (and vice versa).
//
// Nav labels come from the manifest verbatim. Page bodies do use the Paraglide
// catalog: a package ships messages/<locale>.json fragments keyed `ext.<id>.*`,
// which scripts/merge-extension-messages.mjs folds into the compile input.
type PackageMeta = {
    id: string;
    route?: string;
    admin?: { route?: string; label: string; icon?: string };
};

const packageMetas = import.meta.glob('../extensions/packages/**/meta.json', {
    eager: true,
    import: 'default',
}) as Record<string, PackageMeta>;

const adminEntries = import.meta.glob('../extensions/packages/**/admin.tsx') as Record<
    string,
    () => Promise<{ default: ComponentType }>
>;

// Mounted as siblings of the admin `extensions/*` management splat; the static
// first segment makes React Router rank these above it.
export const extensionAdminRoutes: RouteDef[] = Object.entries(packageMetas)
    .map(([path, meta]) => {
        const loader = adminEntries[path.replace(/meta\.json$/, 'admin.tsx')];
        if (!loader || !meta?.id || !meta.admin?.label) return null;

        const id = meta.id;
        return route(`extensions/${meta.admin.route || id}/*`, {
            name: meta.admin.label,
            icon: resolveExtensionIcon(meta.admin.icon),
            category: 'extensions',
            permission: 'extensions.read',
            // Hidden unless the extensions module is on AND this extension is
            // enabled; the extensions.admin API middleware enforces the same
            // state server-side.
            condition: f => f.extensions.enabled && (f.extensions.active ?? []).includes(id),
            // Isolated so a throwing admin extension page cannot take down the
            // admin shell — including the Extensions screen used to disable it.
            element: lazy(async () => ({ default: withExtensionIsolation((await loader()).default, id) })),
        });
    })
    .filter((def): def is RouteDef => def !== null);
