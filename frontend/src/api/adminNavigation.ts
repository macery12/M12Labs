import http from '@/lib/http';
import type { NavLayout } from '@/routes/nav';

// The operator's admin sidebar layout (Navigation editor). `null` is the
// built-in layout: saving null restores it.

export async function getAdminNavigation(): Promise<NavLayout | null> {
    const { data } = await http.get<{ layout: NavLayout | null }>('/api/application/navigation');
    return data.layout;
}

export async function updateAdminNavigation(layout: NavLayout | null): Promise<NavLayout | null> {
    const { data } = await http.put<{ layout: NavLayout | null }>('/api/application/navigation', { layout });
    return data.layout;
}
