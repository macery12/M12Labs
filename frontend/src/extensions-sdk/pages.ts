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
    /** Package-owned server-evaluated booleans that must all be true. */
    requiredFlags?: string[];
}

export interface ExtensionPageManifest {
    id: string;
    /** Manifest display name, shown verbatim. Absent in files written before it was added. */
    name?: string;
    version: string;
    icon: string;
    server: ExtensionPage[];
    admin: ExtensionPage[];
    /** Panel-verified non-page contributions. Absent on older generated files. */
    slots?: ExtensionFrontendSlot[];
    /** How the entry the admin pages fold under reads. Absent unless declared. */
    nav?: { admin?: ExtensionNavEntry };
}

/**
 * Label, icon and order of an extension's admin sidebar entry. No placement:
 * which group the entry sits in is the panel's and the operator's call.
 */
export interface ExtensionNavEntry {
    labelKey: string;
    icon: string;
    order: number;
}

export interface ExtensionFrontendSlot {
    name: 'server-layout.banner' | 'server-layout.overlay';
    entry: string;
    order: number;
    requiredServerPermission?: string;
    requiredFlags?: string[];
}

export type ExtensionFlagValues = Record<string, Record<string, boolean>>;

/** Missing, malformed, and false values all fail closed. */
export function extensionFlagsSatisfied(
    values: ExtensionFlagValues | undefined,
    extensionId: string,
    required: string[] | undefined,
): boolean {
    if (required === undefined || required.length === 0) return true;
    if (!Array.isArray(required) || !required.every(flag => typeof flag === 'string')) return false;

    const packageFlags = values?.[extensionId];
    return packageFlags !== undefined && required.every(flag => packageFlags[flag] === true);
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
