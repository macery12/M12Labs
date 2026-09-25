import type { ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/cn';

export interface SectionNavigationItem {
    to: string;
    label: ReactNode;
    icon?: LucideIcon;
    end?: boolean;
    badge?: ReactNode;
}

export interface SectionNavigationGroup {
    id: string;
    label?: ReactNode;
    items: ReadonlyArray<SectionNavigationItem>;
}

export interface SectionNavigationProps {
    groups: ReadonlyArray<SectionNavigationGroup>;
    ariaLabel?: string;
    className?: string;
}

export interface TabNavigationProps {
    items: ReadonlyArray<SectionNavigationItem>;
    ariaLabel?: string;
    className?: string;
}

function NavigationLink({ item, tab = false }: { item: SectionNavigationItem; tab?: boolean }) {
    const Icon = item.icon;

    return (
        <NavLink
            to={item.to}
            end={item.end}
            className={({ isActive }) =>
                cn(
                    'flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus:outline-none focus:ring-1 focus:ring-[var(--color-focus-ring)]',
                    tab && 'px-3.5',
                    isActive
                        ? 'bg-[var(--brand)]/15 text-[var(--color-ink)] ring-1 ring-[var(--brand)]/30'
                        : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)]',
                )
            }
        >
            {Icon && <Icon aria-hidden="true" className="h-4 w-4 shrink-0" />}
            <span className="whitespace-nowrap">{item.label}</span>
            {item.badge !== undefined && <span className="ml-auto">{item.badge}</span>}
        </NavLink>
    );
}

/** Responsive section rail: horizontal on small screens, grouped rail on large screens. */
export function SectionNavigation({ groups, ariaLabel, className }: SectionNavigationProps) {
    return (
        <nav
            aria-label={ariaLabel}
            className={cn(
                'flex w-full min-w-0 max-w-full shrink-0 gap-4 overflow-x-auto pb-2 lg:w-52 lg:flex-col lg:gap-5 lg:overflow-visible lg:pb-0',
                className,
            )}
        >
            {groups.map(group => (
                <div key={group.id} className="flex shrink-0 flex-col gap-1">
                    {group.label !== undefined && (
                        <p className="hidden px-3 pb-1 text-[10px] font-semibold uppercase tracking-widest text-[var(--color-ink-faint)] lg:block">
                            {group.label}
                        </p>
                    )}
                    <div className="flex gap-1 lg:flex-col">
                        {group.items.map(item => <NavigationLink key={item.to} item={item} />)}
                    </div>
                </div>
            ))}
        </nav>
    );
}

/** Route-backed tab strip. Unlike local-state tabs, each item remains linkable and reload-safe. */
export function TabNavigation({ items, ariaLabel, className }: TabNavigationProps) {
    return (
        <nav
            aria-label={ariaLabel}
            className={cn(
                'flex gap-1 overflow-x-auto rounded-[var(--radius-card)] border border-[var(--color-border-strong)] bg-[var(--color-surface)]/70 p-1',
                className,
            )}
        >
            {items.map(item => <NavigationLink key={item.to} item={item} tab />)}
        </nav>
    );
}
