import { useMemo } from 'react';
import { AppShell } from '@/components/shell/AppShell';
import { RequireAuth } from './RequireAuth';
import { adminRoutes, ADMIN_NAV_DEFAULTS } from '@/routes/admin.routes';
import { applyNavLayout, buildNav } from '@/routes/nav';
import { useFlags } from '@/state/flags';
import { useAdminHeld } from './heldPermissions';
import { RequireAdminIdentity } from './RequireAdminIdentity';
import { useNavigationPreferences } from './navigationPreferences';

export default function AdminLayout() {
    const flags = useFlags(s => s.everest);
    const held = useAdminHeld();
    // The operator's layout rearranges what the registry and the viewer's
    // permissions already allow; each admin's own folds and pins apply on top.
    const groups = useMemo(
        () => applyNavLayout(buildNav(adminRoutes, { flags, held, basePath: '/admin' }), flags?.navigation?.admin, ADMIN_NAV_DEFAULTS),
        [flags, held],
    );
    const defaultCollapsed = useMemo(
        () => groups.flatMap(g => (g.category && g.defaultCollapsed ? [`group:${g.category}`] : [])),
        [groups],
    );
    const sidebarPrefs = useNavigationPreferences('admin', defaultCollapsed);

    return (
        <RequireAuth>
            <RequireAdminIdentity>
                <AppShell groups={groups} sidebarPrefs={sidebarPrefs} />
            </RequireAdminIdentity>
        </RequireAuth>
    );
}
