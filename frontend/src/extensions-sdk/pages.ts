/// <reference types="vite/client" />
import type { ServerCategory, AdminCategory } from '@/routes/registry';

/**
 * The page manifest the panel writes into each installed package
 * (`extension.pages.json`), and the two globs that read it.
 *
 * The file is panel-generated, not package-shipped: it decides nav category,
 * permission and label for every page, which is the exact set of claims the
 * server-side manifest parser exists to verify. A package cannot ship one — the
 * installer overwrites it from the verified manifest on every install and
 * update.
 *
 * A package with no such file contributes zero pages. That is deliberate: the
 * alternative is inferring routes from whatever .tsx files happen to be on
 * disk, which is how a file the manifest never declared ends up mounted.
 */
export interface ExtensionPage {
    slug: string;
    labelKey: string;
    icon: string;
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
}

const SERVER_CATEGORIES: ServerCategory[] = ['general', 'data', 'configuration'];
const ADMIN_CATEGORIES: AdminCategory[] = [
    'general',
    'access',
    'developers',
    'modules',
    'management',
    'extensions',
];

/**
 * Narrow a declared category to one the router knows, falling back to the
 * extensions section. The server validates this too; the fallback is here so a
 * manifest written against a newer panel degrades to a visible page in a
 * sensible place rather than an invisible one.
 */
export function serverCategory(value: string): ServerCategory {
    return (SERVER_CATEGORIES as string[]).includes(value) ? (value as ServerCategory) : 'general';
}

export function adminCategory(value: string): AdminCategory {
    return (ADMIN_CATEGORIES as string[]).includes(value) ? (value as AdminCategory) : 'extensions';
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
