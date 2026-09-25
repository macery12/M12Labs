import { useMemo } from 'react';

/**
 * Namespaced TanStack Query key for an extension.
 *
 * Cache entries are keyed by extension id and installed version so that
 * updating or reinstalling a package cannot serve a previous version's cached
 * response to new code, and two packages can never collide on a key.
 */
export function extensionQueryKey(extensionId: string, version: string, ...parts: ReadonlyArray<unknown>): unknown[] {
    return ['ext', extensionId, version, ...parts];
}

export function useExtensionQueryKey(
    extensionId: string,
    version: string,
    ...parts: ReadonlyArray<unknown>
): unknown[] {
    // Serialized so a caller passing an inline array/object literal does not
    // produce a new key identity every render (React #185 territory). The key
    // is rebuilt from the serialized form so the dependency list is complete;
    // query keys must be JSON-serializable anyway.
    const serialized = JSON.stringify(parts);

    return useMemo(
        () => extensionQueryKey(extensionId, version, ...(JSON.parse(serialized) as unknown[])),
        [extensionId, version, serialized],
    );
}
