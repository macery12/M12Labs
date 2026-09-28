import { useEffect, useMemo, useState } from 'react';
import { getCurrentLocale } from '@/i18n';
import { catalogCovers, initializeMessages } from '@/i18n/messages';
import { Spinner } from '@/components/ui/Spinner';
import { AppShell } from '@/components/shell/AppShell';
import { RequireAuth } from './RequireAuth';
import { adminRoutes, ADMIN_NAV_DEFAULTS } from '@/routes/admin.routes';
import { applyNavLayout, buildNav } from '@/routes/nav';
import { useFlags } from '@/state/flags';
import { useAdminHeld } from './heldPermissions';
import { RequireAdminIdentity } from './RequireAdminIdentity';
import { useNavigationPreferences } from './navigationPreferences';

/**
 * The admin area's copy is not in the catalog the app boots with (players never
 * need it), so load it before anything admin renders. Once loaded it stays: the
 * full catalog is a superset, so returning to the app keeps working.
 */
function useAdminCatalog(): boolean {
    const locale = getCurrentLocale();
    const [ready, setReady] = useState(() => catalogCovers(locale, 'full'));

    useEffect(() => {
        if (ready) return;

        let active = true;
        void initializeMessages(locale, 'full').then(() => {
            if (active) setReady(true);
        });

        return () => {
            active = false;
        };
    }, [locale, ready]);

    return ready;
}

export default function AdminLayout() {
    const catalogReady = useAdminCatalog();
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

    if (!catalogReady) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-[var(--color-canvas)]">
                <Spinner className="h-7 w-7" />
            </div>
        );
    }

    return (
        <RequireAuth>
            <RequireAdminIdentity>
                <AppShell groups={groups} sidebarPrefs={sidebarPrefs} />
            </RequireAdminIdentity>
        </RequireAuth>
    );
}
