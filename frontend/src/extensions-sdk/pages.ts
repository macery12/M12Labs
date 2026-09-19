/// <reference types="vite/client" />
/**
 * The frontend manifest the panel writes into each installed package
 * (`extension.pages.json`), and the two globs that read it.
 *
 * The file is panel-generated, not package-shipped: it decides nav category,
 * permission and label for every page plus every named slot, which is the exact
 * set of claims the server-side manifest parser exists to verify. A package
 * cannot ship one — the installer overwrites it from the verified manifest on
 * every install and update.
 *
 * A package with no such file contributes zero pages and zero slots. That is
 * deliberate: the alternative is inferring UI from whatever .tsx files happen
 * to be on disk, which is how a file the manifest never declared gets mounted.
 */
export interface ExtensionPage {
    slug: string;
    labelKey: string;
    icon: string;
    /**
     * Declared sidebar category, kept for ordering metadata only.
     *
     * Both route generators place extension pages in the Extensions section
     * regardless: which sidebar group a package appears in is the panel's
     * decision, not the package's.
     */
    category: string;
    order: number;
    /** Core server permission needed for a server page. */
    requiredServerPermission?: string;
    /** Full `ext.<id>.admin.<action>` identifier for an admin page. */
    requiredPermission?: string;
}

export interface ExtensionPageManifest {
    id: string;
    version: string;
    icon: string;
    server: ExtensionPage[];
    admin: ExtensionPage[];
    /** Panel-verified non-page contributions. Absent on older generated files. */
    slots?: ExtensionFrontendSlot[];
}

export interface ExtensionFrontendSlot {
    name: 'server-layout.banner' | 'server-layout.overlay';
    entry: string;
    order: number;
    requiredServerPermission?: string;
}

/** Manifests keyed by their file path, for correlating with page modules. */
export function pageManifests(
    globbed: Record<string, unknown>,
): { dir: string; manifest: ExtensionPageManifest }[] {
    return Object.entries(globbed)
        .map(([path, value]) => {
            const manifest = value as ExtensionPageManifest;
            if (!manifest?.id) return null;
            return { dir: path.replace(/extension\.pages\.json$/, ''), manifest };
        })
        .filter((entry): entry is { dir: string; manifest: ExtensionPageManifest } => entry !== null);
}

/** Sort key for nav ordering: declared order, then slug for stability. */
export function byDeclaredOrder(a: ExtensionPage, b: ExtensionPage): number {
    return a.order - b.order || a.slug.localeCompare(b.slug);
}
