import { useMemo, type ReactNode } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ChevronDown, ChevronRight, Pin, PinOff } from 'lucide-react';
import { m, td } from '@/i18n/messages';
import { flattenNav, type NavGroup, type NavItem } from '@/routes/nav';
import { cn } from '@/lib/cn';

/**
 * Per-viewer sidebar state. Only the admin area passes one; without it the
 * sidebar renders every group and extension entry expanded, with no pins,
 * exactly as it always has for the account and server areas.
 */
export interface SidebarPrefs {
    /** `group:<category>` for a group, `ext:<id>` for an extension entry. */
    isCollapsed: (key: string) => boolean;
    toggleCollapsed: (key: string) => void;
    /** Pinned link targets, in pin order. */
    pinned: string[];
    togglePinned: (to: string) => void;
}

const PINNED_GROUP = 'pinned';

// Nav labels come from the route registry (dynamic English strings); look each
// up under nav.items.* with the English name as the fallback so an
// unregistered route still renders. An item carrying an explicit labelKey —
// extension pages, whose labels live in the package's own ext.<id>.* catalog
// rather than under nav.items.* — is resolved from that id instead, and an
// extension's manifest name (`label`) renders verbatim. Categories are a fixed
// set.
const itemLabel = (item: NavItem) =>
    item.label ?? (item.labelKey ? td(item.labelKey, item.name) : td(`nav.items.${item.name}`, item.name));
const categoryLabel = (cat: string) => td(`nav.category.${cat}`, cat);

// `footer` renders below the registry-driven groups, for nav entries that
// aren't routes (the account area passes operator-defined external links).
export function Sidebar({
    groups,
    onNavigate,
    footer,
    prefs,
}: {
    groups: NavGroup[];
    onNavigate?: () => void;
    footer?: ReactNode;
    prefs?: SidebarPrefs;
}) {
    const flat = useMemo(() => flattenNav(groups), [groups]);

    // Longest match wins, rather than NavLink's own isActive.
    //
    // NavLink highlights on any path prefix, so a parent link stays lit while a
    // descendant link is the one actually open — /admin/extensions and the
    // extension page mounted at /admin/extensions/ext/<id>/<slug> under it would
    // both read as active. Picking the single deepest matching link keeps the
    // parent's own sub-routes highlighting it without letting it claim a route
    // another nav item owns.
    const { pathname } = useLocation();
    const activePath = useMemo(() => {
        let best: string | null = null;

        for (const { item } of flat) {
            const matches = item.end
                ? pathname === item.to
                : pathname === item.to || pathname.startsWith(`${item.to.replace(/\/$/, '')}/`);

            if (matches && (best === null || item.to.length > best.length)) best = item.to;
        }

        return best;
    }, [flat, pathname]);

    // Pins resolve against the entries the viewer can see right now, so a pin
    // to a page since hidden by a permission, a feature flag or an uninstall
    // silently drops out instead of rendering a dead link.
    const pinnedGroup = useMemo<NavGroup | null>(() => {
        if (!prefs || prefs.pinned.length === 0) return null;
        const byPath = new Map(flat.map(({ item, parent }) => [item.to, { item, parent }]));
        const items = prefs.pinned.flatMap(to => {
            const hit = byPath.get(to);
            if (!hit) return [];
            // A nested extension page may carry a short label ("Settings")
            // that only makes sense under its parent; pinned, it stands alone.
            const label = hit.parent ? `${itemLabel(hit.parent)} · ${itemLabel(hit.item)}` : undefined;
            return [{ ...hit.item, key: `pin:${to}`, label: label ?? hit.item.label }];
        });
        return items.length > 0 ? { category: PINNED_GROUP, items } : null;
    }, [flat, prefs]);

    const shown = pinnedGroup ? [pinnedGroup, ...groups] : groups;
    const pinnedSet = useMemo(() => new Set(prefs?.pinned ?? []), [prefs]);

    const holdsActive = (item: NavItem): boolean =>
        item.children ? item.children.some(c => c.to === activePath) : item.to === activePath;

    const linkClass = (active: boolean, nested = false) =>
        cn(
            'flex w-full items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors',
            nested ? 'py-1.5' : 'py-2',
            active
                ? 'bg-[var(--brand)]/15 text-[var(--color-ink)] ring-1 ring-[var(--brand)]/30'
                : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)]',
        );

    const renderLink = (item: NavItem, nested = false) => {
        const isActive = item.to === activePath;
        const pinned = pinnedSet.has(item.to);
        const label = itemLabel(item);

        return (
            <div key={item.key} className="group/row relative">
                <Link
                    to={item.to}
                    aria-current={isActive ? 'page' : undefined}
                    onClick={onNavigate}
                    className={cn(linkClass(isActive, nested), prefs && 'pr-9')}
                >
                    {item.icon && <item.icon className="h-[18px] w-[18px] shrink-0" />}
                    <span className="truncate">{label}</span>
                </Link>
                {prefs && (
                    <button
                        type="button"
                        onClick={() => prefs.togglePinned(item.to)}
                        aria-label={pinned ? m['nav.sidebar.unpin']({ name: label }) : m['nav.sidebar.pin']({ name: label })}
                        title={pinned ? m['nav.sidebar.unpin']({ name: label }) : m['nav.sidebar.pin']({ name: label })}
                        className={cn(
                            'absolute right-1.5 top-1/2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md text-[var(--color-ink-faint)] transition-opacity hover:bg-[var(--color-surface)] hover:text-[var(--color-ink)]',
                            'pointer-events-none opacity-0 group-hover/row:pointer-events-auto group-hover/row:opacity-100 focus-visible:pointer-events-auto focus-visible:opacity-100',
                        )}
                    >
                        {pinned ? <PinOff className="h-3.5 w-3.5" /> : <Pin className="h-3.5 w-3.5" />}
                    </button>
                )}
            </div>
        );
    };

    const renderItem = (item: NavItem) => {
        if (!item.children) return renderLink(item);

        // An extension with several pages: a toggle row over its pages. Folded
        // away, it takes the active highlight when one of its pages is open so
        // the sidebar still shows where you are.
        const collapsed = prefs?.isCollapsed(item.key) ?? false;

        return (
            <div key={item.key} className="flex flex-col gap-1">
                <button
                    type="button"
                    aria-expanded={!collapsed}
                    disabled={!prefs}
                    onClick={() => prefs?.toggleCollapsed(item.key)}
                    className={cn(linkClass(collapsed && holdsActive(item)), 'text-left disabled:cursor-default')}
                >
                    {item.icon && <item.icon className="h-[18px] w-[18px] shrink-0" />}
                    <span className="flex-1 truncate">{itemLabel(item)}</span>
                    {prefs &&
                        (collapsed ? (
                            <ChevronRight className="h-4 w-4 shrink-0 text-[var(--color-ink-faint)]" />
                        ) : (
                            <ChevronDown className="h-4 w-4 shrink-0 text-[var(--color-ink-faint)]" />
                        ))}
                </button>
                {!collapsed && (
                    <div className="ml-5 flex flex-col gap-1 border-l border-[var(--color-border)] pl-2">
                        {item.children.map(child => renderLink(child, true))}
                    </div>
                )}
            </div>
        );
    };

    return (
        <nav className="flex flex-col gap-6 p-4">
            {shown.map((group, i) => {
                const groupKey = `group:${group.category}`;
                const collapsed = !!(prefs && group.category && prefs.isCollapsed(groupKey));
                // A folded group still shows the page you're on, so opening a
                // System page from the palette never leaves the sidebar blank.
                const items = collapsed ? group.items.filter(holdsActive) : group.items;

                return (
                    <div key={group.category ?? `g${i}`} className="flex flex-col gap-1">
                        {group.category &&
                            (prefs ? (
                                <button
                                    type="button"
                                    aria-expanded={!collapsed}
                                    onClick={() => prefs.toggleCollapsed(groupKey)}
                                    className="group/h flex items-center gap-2 rounded-md px-3 pb-1 text-left text-xs font-medium uppercase tracking-wider text-[var(--color-ink-faint)] hover:text-[var(--color-ink-muted)]"
                                >
                                    <span className="flex-1 truncate">{categoryLabel(group.category)}</span>
                                    {collapsed && (
                                        <span className="font-mono normal-case tracking-normal">{group.items.length}</span>
                                    )}
                                    {collapsed ? (
                                        <ChevronRight className="h-3.5 w-3.5 shrink-0" />
                                    ) : (
                                        <ChevronDown className="h-3.5 w-3.5 shrink-0 opacity-0 transition-opacity group-hover/h:opacity-100 group-focus-visible/h:opacity-100" />
                                    )}
                                </button>
                            ) : (
                                <p className="px-3 pb-1 text-xs font-medium uppercase tracking-wider text-[var(--color-ink-faint)]">
                                    {categoryLabel(group.category)}
                                </p>
                            ))}
                        {items.map(renderItem)}
                    </div>
                );
            })}
            {footer}
        </nav>
    );
}
