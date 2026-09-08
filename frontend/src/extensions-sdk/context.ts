import { useMemo } from 'react';
import { can } from '@/lib/can';
import { useServer } from '@/components/server/ServerContext';
import { useAdminPermissions } from '@/layouts/heldPermissions';
import type { ServerDetail } from '@/api/servers';

// Module-level constant so a server with no permissions yields a referentially
// stable array. Building a fresh [] per render makes every dependent useMemo
// recompute, which is React #185 (infinite re-render) territory — the same trap
// documented in layouts/heldPermissions.ts.
const NO_PERMISSIONS: string[] = [];

export interface ExtensionServerContext {
    server: ServerDetail;
    /** Subuser permissions the viewer holds on this server. */
    permissions: string[];
    /** Whether the viewer holds a core server permission, e.g. 'allocation.update'. */
    can(permission: string | string[]): boolean;
}

/**
 * Server, and the viewer's permissions on it, for a per-server extension page.
 *
 * Hiding UI is not authorization — the backend FormRequest is. Use this to
 * avoid showing controls that would 403, never as the only gate.
 */
export function useExtensionServerContext(): ExtensionServerContext {
    const server = useServer();

    return useMemo(() => {
        const permissions = server.permissions ?? NO_PERMISSIONS;

        return { server, permissions, can: (permission: string | string[]) => can(permissions, permission) };
    }, [server]);
}

export interface ExtensionAdminContext {
    /** True while the permission set is loading — do not deny access yet. */
    isLoading: boolean;
    /** Whether the admin holds `ext.<id>.admin.<action>` for this extension. */
    can(action: string): boolean;
}

/**
 * The viewer's dynamic admin permissions for one extension.
 *
 * Actions are declared in the package manifest and namespaced by the panel as
 * `ext.<id>.admin.<action>`; a package never chooses the prefix.
 */
export function useExtensionAdminContext(extensionId: string): ExtensionAdminContext {
    const { held, isLoading } = useAdminPermissions();

    return useMemo(
        () => ({
            isLoading,
            can: (action: string) => can(held, `ext.${extensionId}.admin.${action}`),
        }),
        [held, isLoading, extensionId],
    );
}
