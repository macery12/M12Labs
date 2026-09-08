/// <reference types="vite/client" />
import { lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { withExtensionIsolation } from '@/extensions-sdk/ExtensionErrorBoundary';
import {
    pageManifests,
    byDeclaredOrder,
    serverCategory,
    type ExtensionPage,
} from '@/extensions-sdk/pages';
import { route, type RouteDef, type ServerCategory } from '@/routes/registry';
import { td } from '@/i18n/messages';
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
                    category: serverCategory(page.category),
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
 * one appears in its declared sidebar category instead of being buried behind
 * a single "Extensions" tab.
 *
 * The path keeps the extensions/ext/<id>/ prefix on purpose. A top-level
 * segment chosen by a package could shadow /files or /backups, and a
 * route-ranking collision with a core route is a security problem rather than
 * a cosmetic one.
 *
 * Known limitation, shared with core: RouteDef.name is a plain string and nav
 * reads it once at module scope, so switching locale does not re-render these
 * labels until the page reloads. Core routes use literals and behave the same.
 */
export const extensionServerRoutes: RouteDef[] = extensionRoutes.map(def =>
    route(`extensions/ext/${def.id}/${def.slug}/*`, {
        name: td(def.labelKey, def.slug),
        icon: resolveExtensionIcon(def.icon),
        category: def.category,
        permission: def.requiredServerPermission,
        // Hidden unless the module is on AND this extension is enabled; the
        // client API middleware enforces the same state server-side.
        condition: f => f.extensions.enabled && (f.extensions.active ?? []).includes(def.id),
        element: def.component,
    }),
);
