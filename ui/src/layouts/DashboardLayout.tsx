import { useMemo } from 'react';
import { AppShell } from '@/components/shell/AppShell';
import { RequireAuth } from './RequireAuth';
import { accountRoutes } from '@/routes/account.routes';
import { buildNav } from '@/routes/nav';
import { useFlags } from '@/state/flags';

export default function DashboardLayout() {
    const flags = useFlags(s => s.everest);
    const groups = useMemo(
        () => buildNav(accountRoutes, { flags, held: [], basePath: '/v2/account' }),
        [flags],
    );

    return (
        <RequireAuth>
            <AppShell groups={groups} />
        </RequireAuth>
    );
}
