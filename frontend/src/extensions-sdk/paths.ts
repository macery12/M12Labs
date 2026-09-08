/*
 * Loader-owned route namespaces.
 *
 * A package never names its own prefix: the panel derives these from the
 * package directory on the server, and the SDK mirrors them here so a package
 * cannot address another extension's endpoints or reach a core path. Kept free
 * of imports so it stays trivially testable and cheap to load.
 */

/** Percent-encode a single path segment, rejecting anything that could escape. */
function segment(value: string): string {
    if (value === '' || value.includes('/') || value.includes('\\') || value.includes('..')) {
        throw new Error(`Invalid extension path segment: ${JSON.stringify(value)}`);
    }
    return encodeURIComponent(value);
}

export function clientExtensionBase(serverId: string, extensionId: string): string {
    return `/api/client/servers/${segment(serverId)}/extensions/ext/${segment(extensionId)}`;
}

export function adminExtensionBase(extensionId: string): string {
    return `/api/application/extensions/ext/${segment(extensionId)}`;
}

/** Join a caller-supplied path onto a namespace base. */
export function joinExtensionPath(base: string, path: string): string {
    if (path === '' || path === '/') return base;
    return path.startsWith('/') ? `${base}${path}` : `${base}/${path}`;
}
