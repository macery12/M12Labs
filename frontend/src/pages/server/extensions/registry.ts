/// <reference types="vite/client" />
import { lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { withExtensionIsolation } from '@/extensions-sdk/ExtensionErrorBoundary';

// Extension package registry. Ported from V1's components/server/extensions/registry.ts:
// packages under src/extensions/packages/<name>/ self-register by shipping a
// meta.json ({ id, route }) next to an index.tsx default-exporting the page.
// No central list to edit — dropping the folder in is the whole install.
export interface ExtensionRouteDefinition {
    id: string;
    route: string;
    component: LazyExoticComponent<ComponentType>;
}

type PackageMeta = { id: string; route?: string };

const packageMetas = import.meta.glob('../../../extensions/packages/**/meta.json', {
    eager: true,
    import: 'default',
}) as Record<string, PackageMeta>;

const packageComponents = import.meta.glob('../../../extensions/packages/**/index.tsx') as Record<
    string,
    () => Promise<{ default: ComponentType }>
>;

export const extensionRoutes: ExtensionRouteDefinition[] = Object.entries(packageMetas)
    .map(([path, meta]) => {
        const loader = packageComponents[path.replace(/meta\.json$/, 'index.tsx')];
        if (!loader || !meta?.id) return null;

        // Every extension page gets its own error boundary and suspense: extension
        // code shares this React tree, so an unisolated throw would unmount the
        // whole server route including the navigation away from it.
        return {
            id: meta.id,
            route: meta.route || meta.id,
            component: lazy(async () => ({ default: withExtensionIsolation((await loader()).default, meta.id) })),
        };
    })
    .filter((route): route is ExtensionRouteDefinition => route !== null);
