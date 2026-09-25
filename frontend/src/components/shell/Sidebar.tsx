import { useMemo, type ReactNode } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { td } from '@/i18n/messages';
import type { NavGroup, NavItem } from '@/routes/nav';
import { cn } from '@/lib/cn';

// `footer` renders below the registry-driven groups, for nav entries that
// aren't routes (the account area passes operator-defined external links).
export function Sidebar({
    groups,
    onNavigate,
    footer,
}: {
    groups: NavGroup[];
    onNavigate?: () => void;
    footer?: ReactNode;
}) {
    // Nav labels come from the route registry (dynamic English strings); look
    // each up under nav.items.* with the English name as the fallback so an
    // unregistered route still renders. An item carrying an explicit labelKey
    // — extension pages, whose labels live in the package's own ext.<id>.*
    // catalog rather than under nav.items.* — is resolved from that id instead.
    // Categories are a fixed set.
    const itemLabel = (item: NavItem) =>
        item.labelKey ? td(item.labelKey, item.name) : td(`nav.items.${item.name}`, item.name);
    const categoryLabel = (cat: string) => td(`nav.category.${cat}`, cat);

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

        for (const group of groups) {
            for (const item of group.items) {
                const matches = item.end
                    ? pathname === item.to
                    : pathname === item.to || pathname.startsWith(`${item.to.replace(/\/$/, '')}/`);

                if (matches && (best === null || item.to.length > best.length)) best = item.to;
            }
        }

        return best;
    }, [groups, pathname]);

    return (
        <nav className="flex flex-col gap-6 p-4">
            {groups.map((group, i) => (
                <div key={group.category ?? `g${i}`} className="flex flex-col gap-1">
                    {group.category && (
                        <p className="px-3 pb-1 text-xs font-medium uppercase tracking-wider text-[var(--color-ink-faint)]">
                            {categoryLabel(group.category)}
                        </p>
                    )}
                    {group.items.map(item => {
                        const isActive = item.to === activePath;

                        return (
                            <Link
                                key={item.to}
                                to={item.to}
                                aria-current={isActive ? 'page' : undefined}
                                onClick={onNavigate}
                                className={cn(
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                    isActive
                                        ? 'bg-[var(--brand)]/15 text-[var(--color-ink)] ring-1 ring-[var(--brand)]/30'
                                        : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)]',
                                )}
                            >
                                {item.icon && <item.icon className="h-[18px] w-[18px] shrink-0" />}
                                <span className="truncate">{itemLabel(item)}</span>
                            </Link>
                        );
                    })}
                </div>
            ))}
            {footer}
        </nav>
    );
}
