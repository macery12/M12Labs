/// <reference types="vite/client" />
import { lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { withExtensionIsolation } from '@/extensions-sdk/ExtensionErrorBoundary';
import { pageManifests, byDeclaredOrder, type ExtensionPage } from '@/extensions-sdk/pages';
import { route, type RouteDef, type ServerCategory } from '@/routes/registry';
import { resolveExtensionIcon } from '@/pages/admin/extensions/extMeta';

// Server pages contributed by installed extension packages.
//
// Driven by the panel-written extension.pages.json rather than by globbing for
// entry files: a package that ships a page it never declared contributes
// nothing, and the nav category, label and permission all come from a manifest
// the installer verified. A package may now ship several pages instead of the
// single index.tsx the v2 layout allowed.
export interface ExtensionRouteDefinition {
    id: string;
    /** URL segment under /server/:id/extensions/ext/<id>/. */
    slug: string;
    labelKey: string;
    icon: string;
    category: ServerCategory;
    order: number;
    requiredServerPermission?: string;
    component: LazyExoticComponent<ComponentType>;
}

const manifests = import.meta.glob('../../../extensions/packages/*/extension.pages.json', {
    eager: true,
    import: 'default',
});

const pageModules = import.meta.glob('../../../extensions/packages/*/pages/server/*.tsx') as Record<
    string,
    () => Promise<{ default: ComponentType }>
>;

export const extensionRoutes: ExtensionRouteDefinition[] = pageManifests(manifests)
    .flatMap(({ dir, manifest }) =>
        [...manifest.server].sort(byDeclaredOrder).flatMap((page: ExtensionPage) => {
            const loader = pageModules[`${dir}pages/server/${page.slug}.tsx`];
            // A declared page whose entry file is absent is skipped rather than
            // mounted as a broken route. The server-side parser rejects this
            // pairing at install, so reaching it means the files were edited
            // afterwards.
            if (!loader) return [];

            return [
                {
                    id: manifest.id,
                    slug: page.slug,
                    labelKey: page.labelKey,
                    icon: page.icon,
                    category: 'extensions' as const,
                    order: page.order,
                    requiredServerPermission: page.requiredServerPermission,
                    // Every extension page gets its own error boundary: extension
                    // code shares this React tree, so an unisolated throw would
                    // unmount the whole server route including the navigation
                    // away from it.
                    component: lazy(async () => ({
                        default: withExtensionIsolation((await loader()).default, manifest.id),
                    })),
                },
            ];
        }),
    )
    .sort((a, b) => a.order - b.order || a.id.localeCompare(b.id));

/**
 * The same pages as RouteDefs, mounted directly under the server area so each
 * one gets its own sidebar entry instead of being buried behind a single
 * "Extensions" tab.
 *
 * They group under Extensions rather than the category the manifest declares.
 * Placement is the panel's call: an installed package should never interleave
 * its screens with Files, Backups and Startup, where a user has no way to tell
 * core apart from third-party.
 *
 * The path keeps the extensions/ext/<id>/ prefix on purpose. A top-level
 * segment chosen by a package could shadow /files or /backups, and a
 * route-ranking collision with a core route is a security problem rather than
 * a cosmetic one.
 */
export const extensionServerRoutes: RouteDef[] = extensionRoutes.map(def =>
    route(`extensions/ext/${def.id}/${def.slug}/*`, {
        // Resolved by the sidebar, not here: this runs at module scope, before
        // the locale catalog loads.
        name: def.slug,
        labelKey: def.labelKey,
        icon: resolveExtensionIcon(def.icon),
        category: def.category,
        permission: def.requiredServerPermission,
        // Hidden unless the module is on AND this extension is enabled; the
        // client API middleware enforces the same state server-side.
        condition: f => f.extensions.enabled && (f.extensions.active ?? []).includes(def.id),
        element: def.component,
    }),
);
