import { existsSync, readFileSync } from 'node:fs';
import type { Plugin } from 'vite';

// Which extension packages a build may compile.
//
// The panel writes `src/extensions/installed.json` right before every build
// (ExtensionBuildInputsService): the packages that have a database row and
// whose frontend files match their signed manifest. Without it the globs
// below took every directory under `src/extensions/packages/`, so an orphan
// directory -- or a file dropped into one -- was compiled into the panel with
// no signature or database check.
//
// No file means no panel database (a dev checkout, CI): every directory is
// included, as before. This is a build input, never read at runtime.

const EXTENSION_ID = /^[a-z0-9_]+$/;

export function readExtensionAllowlist(file: string): string[] | null {
    if (!existsSync(file)) return null;

    const doc: unknown = JSON.parse(readFileSync(file, 'utf8'));
    const ids = typeof doc === 'object' && doc !== null ? (doc as { ids?: unknown }).ids : undefined;

    // The ids are spliced into source below, so anything unexpected is fatal.
    if (!Array.isArray(ids) || !ids.every(id => typeof id === 'string' && EXTENSION_ID.test(id))) {
        throw new Error(`${file} is not a valid extension allowlist`);
    }

    return [...new Set(ids as string[])].sort();
}

// `import.meta.glob('<prefix>packages/*/<rest>'` -- one wildcard package segment.
const PACKAGE_GLOB = /import\.meta\.glob\(\s*(['"])((?:[^'"\n]*\/)?packages)\/\*\/([^'"\n]+)\1/g;

/** Rewrite each package glob to name the allowed packages explicitly. */
export function restrictPackageGlobs(code: string, ids: string[]): string {
    return code.replace(PACKAGE_GLOB, (_match, quote: string, prefix: string, rest: string) => {
        const patterns = (ids.length > 0 ? ids : ['__no_installed_extensions__']).map(
            id => `${quote}${prefix}/${id}/${rest}${quote}`,
        );

        return `import.meta.glob([${patterns.join(', ')}]`;
    });
}

export function extensionAllowlistPlugin(allowlistFile: string, sourceRoot: string): Plugin {
    let ids: string[] | null = null;

    return {
        name: 'm12labs-extension-allowlist',
        // Before Vite's own glob expansion sees the pattern.
        enforce: 'pre',
        buildStart() {
            ids = readExtensionAllowlist(allowlistFile);
        },
        transform(code, id) {
            if (ids === null || !id.startsWith(sourceRoot) || !code.includes('import.meta.glob')) return null;

            const rewritten = restrictPackageGlobs(code, ids);

            return rewritten === code ? null : { code: rewritten, map: null };
        },
    };
}
