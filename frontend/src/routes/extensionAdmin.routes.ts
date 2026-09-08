/// <reference types="vite/client" />
import { lazy, type ComponentType } from 'react';
import { withExtensionIsolation } from '@/extensions-sdk/ExtensionErrorBoundary';
import { pageManifests, byDeclaredOrder, adminCategory, type ExtensionPage } from '@/extensions-sdk/pages';
import { route, type RouteDef } from './registry';
import { resolveExtensionIcon } from '@/pages/admin/extensions/extMeta';
import { td } from '@/i18n/messages';

// Admin pages contributed by installed extension packages.
//
// Driven by the panel-written extension.pages.json, the same file the server
// registry reads. What changes versus the v2 layout is that a package may ship
// several admin pages instead of one admin.tsx, and that the nav category and
// required permission come from the verified manifest rather than being
// hardcoded to 'extensions' / 'extensions.read' here — an extension's admin
// screens are no longer all gated by the permission that also manages
// extensions themselves.
//
// Page bodies use the Paraglide catalog: a package ships messages/<locale>.json
// fragments keyed `ext.<id>.*`, which scripts/merge-extension-messages.mjs
// folds into the compile input.
const manifests = import.meta.glob('../extensions/packages/*/extension.pages.json', {
    eager: true,
    import: 'default',
});

const pageModules = import.meta.glob('../extensions/packages/*/pages/admin/*.tsx') as Record<
    string,
    () => Promise<{ default: ComponentType }>
>;

// Mounted as siblings of the admin `extensions/*` management splat; the static
// segments make React Router rank these above it.
export const extensionAdminRoutes: RouteDef[] = pageManifests(manifests)
    .flatMap(({ dir, manifest }) =>
        [...manifest.admin].sort(byDeclaredOrder).flatMap((page: ExtensionPage) => {
            const loader = pageModules[`${dir}pages/admin/${page.slug}.tsx`];
            // A declared page with no entry file is skipped rather than mounted
            // as a broken route. The parser rejects that pairing at install, so
            // reaching it means the files were edited afterwards.
            if (!loader) return [];

            const id = manifest.id;

            return [
                route(`extensions/ext/${id}/${page.slug}/*`, {
                    // Known limitation, shared with core: nav reads RouteDef.name
                    // once at module scope, so a locale switch does not re-render
                    // these labels until the page reloads.
                    name: td(page.labelKey, page.slug),
                    icon: resolveExtensionIcon(page.icon),
                    category: adminCategory(page.category),
                    // The full ext.<id>.admin.<action> identifier, expanded
                    // server-side when the manifest was written.
                    permission: page.requiredPermission ?? 'extensions.read',
                    // Hidden unless the extensions module is on AND this
                    // extension is enabled; the extensions.admin API middleware
                    // enforces the same state server-side.
                    condition: f => f.extensions.enabled && (f.extensions.active ?? []).includes(id),
                    // Isolated so a throwing admin extension page cannot take
                    // down the admin shell — including the Extensions screen
                    // used to disable it.
                    element: lazy(async () => ({
                        default: withExtensionIsolation((await loader()).default, id),
                    })),
                }),
            ];
        }),
    );
