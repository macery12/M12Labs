import http from '@/lib/http';

// Per-user sidebar state, stored on the account so it follows an admin across
// devices. Only the admin area keeps any today.

export type NavigationArea = 'admin';

export interface NavigationPreferences {
    /** Pinned link targets, in pin order. */
    pinned: string[];
    /** Explicit fold choices keyed `group:<category>` / `ext:<id>`; absent keys use the sidebar default. */
    collapsed: Record<string, boolean>;
}

export const NAV_MAX_PINNED = 12;

function normalise(data: Partial<NavigationPreferences> | undefined): NavigationPreferences {
    return {
        pinned: Array.isArray(data?.pinned) ? data.pinned : [],
        // Tolerate a bare [] for an empty map, whatever serialises it.
        collapsed: data?.collapsed && !Array.isArray(data.collapsed) ? data.collapsed : {},
    };
}

export async function getNavigationPreferences(area: NavigationArea): Promise<NavigationPreferences> {
    const { data } = await http.get<NavigationPreferences>(`/api/client/account/navigation/${area}`);
    return normalise(data);
}

export async function updateNavigationPreferences(
    area: NavigationArea,
    prefs: NavigationPreferences,
): Promise<void> {
    await http.put(`/api/client/account/navigation/${area}`, prefs);
}
