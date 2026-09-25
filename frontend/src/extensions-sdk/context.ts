import { useMemo } from 'react';
import { can } from '@/lib/can';
import { useServer } from '@/components/server/ServerContext';
import { useSession } from '@/state/session';
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

export interface ExtensionViewer {
    /** Stable id for the signed-in account. Safe to key UI state on. */
    uuid: string;
    username: string;
    email: string;
    /** Avatar URL the panel already resolved, or '' when there is none. */
    avatarUrl: string;
}

/**
 * Who is looking at the page, for display.
 *
 * Deliberately four fields and no more. A package rendering a transcript, an
 * audit trail or an author byline needs a name to put next to the viewer's own
 * entries, and the alternative — writing "You" everywhere — loses the thing
 * that makes a shared record readable.
 *
 * Nothing here decides anything. Roles, admin status and the access profile are
 * absent on purpose: a package asking "is this person an admin" is asking the
 * wrong question, because the answer it actually needs is whether they hold a
 * specific permission, which is {@see useExtensionAdminContext}. And hiding UI
 * is not authorization in either case — the backend FormRequest is.
 *
 * Null before the bootstrap has landed, which is a real state on a cold load
 * rather than a signed-out one, so render a neutral label rather than treating
 * it as absence of a user.
 */
export function useExtensionViewer(): ExtensionViewer | null {
    const user = useSession(state => state.user);

    return useMemo(
        () =>
            user === null
                ? null
                : {
                      uuid: user.uuid,
                      username: user.username,
                      email: user.email,
                      avatarUrl: user.avatar_url ?? '',
                  },
        [user],
    );
}
