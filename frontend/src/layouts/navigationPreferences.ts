import { useEffect, useMemo, useRef } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { m } from '@/i18n/messages';
import { useFlashes } from '@/state/flashes';
import { useSession } from '@/state/session';
import {
    getNavigationPreferences,
    updateNavigationPreferences,
    NAV_MAX_PINNED,
    type NavigationArea,
    type NavigationPreferences,
} from '@/api/navigationPreferences';
import type { SidebarPrefs } from '@/components/shell/Sidebar';

const EMPTY: NavigationPreferences = { pinned: [], collapsed: {} };

// How long to wait after the last toggle before saving, so collapsing three
// groups in a row is one request, not three racing ones.
const SAVE_DELAY_MS = 400;

/**
 * Sidebar state for one area, stored on the account.
 *
 * The query cache is the working copy: toggles write it immediately, so the
 * sidebar responds without waiting on the network, and a debounced PUT sends
 * the whole state afterwards. Failures are ignored on both legs. This is a
 * convenience, and a panel whose table isn't migrated yet should still get a
 * working (if forgetful) sidebar with the defaults rather than error toasts
 * on every click.
 */
export function useNavigationPreferences(area: NavigationArea, defaultCollapsed: string[]): SidebarPrefs {
    const queryClient = useQueryClient();
    const push = useFlashes(s => s.push);
    const queryKey = useMemo(() => ['account', 'navigation', area], [area]);
    // Same gate as the admin permission set: this runs in the layout, ahead of
    // RequireAuth, and a visitor with no admin profile has nothing to load.
    const isAdmin = useSession(s => Boolean(s.user?.admin_role_id));

    const { data } = useQuery({
        queryKey,
        queryFn: () => getNavigationPreferences(area),
        enabled: isAdmin,
        staleTime: Infinity,
        retry: false,
    });

    const pending = useRef<{ timer: ReturnType<typeof setTimeout>; prefs: NavigationPreferences } | null>(null);

    // Flush rather than drop a save still waiting when the admin area unmounts.
    useEffect(
        () => () => {
            if (!pending.current) return;
            clearTimeout(pending.current.timer);
            void updateNavigationPreferences(area, pending.current.prefs).catch(() => undefined);
            pending.current = null;
        },
        [area],
    );

    return useMemo(() => {
        const current = data ?? EMPTY;

        const save = (next: NavigationPreferences) => {
            queryClient.setQueryData(queryKey, next);
            if (pending.current) clearTimeout(pending.current.timer);
            pending.current = {
                prefs: next,
                timer: setTimeout(() => {
                    pending.current = null;
                    void updateNavigationPreferences(area, next).catch(() => undefined);
                }, SAVE_DELAY_MS),
            };
        };

        return {
            isCollapsed: key => current.collapsed[key] ?? defaultCollapsed.includes(key),
            toggleCollapsed: key => {
                const fallback = defaultCollapsed.includes(key);
                const next = !(current.collapsed[key] ?? fallback);
                // Store only departures from the default, so the map stays
                // small and a later change of default still reaches anyone who
                // never touched that group.
                const { [key]: _previous, ...rest } = current.collapsed;
                save({ ...current, collapsed: next === fallback ? rest : { ...rest, [key]: next } });
            },
            pinned: current.pinned,
            togglePinned: to => {
                if (current.pinned.includes(to)) {
                    save({ ...current, pinned: current.pinned.filter(p => p !== to) });
                    return;
                }
                if (current.pinned.length >= NAV_MAX_PINNED) {
                    push({ type: 'warning', message: m['nav.sidebar.pinLimit']({ max: NAV_MAX_PINNED }) });
                    return;
                }
                save({ ...current, pinned: [...current.pinned, to] });
            },
        };
    }, [area, data, defaultCollapsed, push, queryClient, queryKey]);
}
