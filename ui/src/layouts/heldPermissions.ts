import { useMemo } from 'react';
import { useSession } from '@/state/session';

// Phase 1 permission sourcing. Real per-server subuser permissions and the
// admin permission set (/api/application/permissions) arrive in later phases;
// for now root/admin users get '*', everyone else gets the empty set so the
// permission-filtering machinery in the sidebar is exercised honestly.
//
// IMPORTANT: select a primitive from the store and derive the array via useMemo.
// Returning a fresh array straight from the selector gives useSyncExternalStore
// an unstable snapshot every render → infinite re-render loop (React #185).
export function useAdminHeld(): string[] {
    const isAdmin = useSession(s => Boolean(s.user?.root_admin || s.user?.admin_role_id));
    return useMemo(() => (isAdmin ? ['*'] : []), [isAdmin]);
}

export function useServerHeld(): string[] {
    const isAdmin = useSession(s => Boolean(s.user?.root_admin));
    return useMemo(() => (isAdmin ? ['*'] : []), [isAdmin]);
}
