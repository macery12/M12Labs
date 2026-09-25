import type { LucideIcon } from 'lucide-react';
import type { Flags, RouteDef } from './registry';
import { can } from '@/lib/can';

export interface NavItem {
    /**
     * Stable identity: the link target for a page, `ext:<id>` for an
     * extension's parent entry. Collapse state is keyed on it.
     */
    key: string;
    /**
     * Base-path-free id the operator's layout refers to: the route path for a
     * core page (`infrastructure`, `access/users`), `ext:<id>` for an
     * extension's entry whether it has one page or several.
     */
    id: string;
    to: string;
    name: string;
    /** Message id for the label; preferred over `name` when present. */
    labelKey?: string;
    /** Label rendered as-is, ahead of `labelKey` and `name` (extension manifest names). */
    label?: string;
    icon?: LucideIcon;
    category?: string;
    end?: boolean;
    /** Present on an extension's parent entry: its pages, in declared order. */
    children?: NavItem[];
}

export interface NavGroup {
    category: string | null;
    items: NavItem[];
    /** Operator-chosen name, rendered verbatim instead of nav.category.<category>. */
    label?: string;
    /** Whether the group starts folded for an admin who has not chosen. */
    defaultCollapsed?: boolean;
}

function routeId(routePath: string): string {
    return routePath.replace(/\/?\*$/, '');
}

function toPath(basePath: string, routePath: string): string {
    const clean = routePath.replace(/\/?\*$/, ''); // drop trailing /* or *
    if (clean === '') return basePath || '/'; // account mounts at the root
    return `${basePath}/${clean}`;
}

// Filter the registry to nav-visible entries (name + permission + flag gates),
// resolve their links, and group by category preserving registry order.
//
// Pages carrying `extension` fold under one parent entry per extension. The
// gates run per page first, so a parent only ever holds pages the viewer can
// open, and an extension whose pages are all hidden contributes nothing.
export function buildNav(
    routes: RouteDef[],
    opts: { flags: Flags | null; held: string[]; basePath: string },
): NavGroup[] {
    const groups: NavGroup[] = [];
    const byCategory = new Map<string | null, NavItem[]>();
    const parents = new Map<string, NavItem>();

    const push = (category: string | null, item: NavItem) => {
        if (!byCategory.has(category)) {
            byCategory.set(category, []);
            groups.push({ category, items: byCategory.get(category)! });
        }
        byCategory.get(category)!.push(item);
    };

    for (const r of routes) {
        if (!r.name) continue; // unnamed routes are reachable but hidden
        if (r.path.includes(':')) continue; // parameterized detail routes aren't nav targets
        if (r.condition && opts.flags && !r.condition(opts.flags)) continue;
        if (r.permission && !can(opts.held, r.permission)) continue;

        const category = r.category ?? null;
        const to = toPath(opts.basePath, r.path);
        const item: NavItem = {
            key: to,
            id: routeId(r.path),
            to,
            name: r.name,
            labelKey: r.labelKey,
            icon: r.icon,
            category: r.category,
            end: r.end,
        };

        if (!r.extension) {
            push(category, item);
            continue;
        }

        const key = `ext:${r.extension.id}`;
        const parent = parents.get(key);
        if (parent) {
            parent.children!.push(item);
            continue;
        }

        // Without a manifest name the first page's label stands in, which is
        // what the sidebar showed for that page before pages were grouped.
        const created: NavItem = {
            key,
            id: key,
            to,
            name: r.name,
            label: r.extension.name,
            labelKey: r.extension.name ? undefined : r.labelKey,
            icon: r.extension.icon ?? r.icon,
            category: r.category,
            children: [item],
        };
        parents.set(key, created);
        push(category, created);
    }

    // A single-page extension links straight to its page under the
    // extension's name; a parent with one child is a click for nothing.
    for (const group of groups) {
        group.items = group.items.map((item): NavItem => {
            const only = item.children?.length === 1 ? item.children[0] : undefined;
            if (!only) return item;

            return {
                ...only,
                id: item.id,
                label: item.label,
                labelKey: item.label ? undefined : only.labelKey,
                icon: item.icon ?? only.icon,
            };
        });
    }

    return groups;
}

// Every linkable entry, parents replaced by their pages — for consumers that
// list destinations rather than render the tree (the command palette, pins).
export interface FlatNavEntry {
    item: NavItem;
    parent: NavItem | null;
    group: NavGroup;
}

export function flattenNav(groups: NavGroup[]): FlatNavEntry[] {
    return groups.flatMap(group =>
        group.items.flatMap((item): FlatNavEntry[] =>
            item.children
                ? item.children.map(child => ({ item: child, parent: item, group }))
                : [{ item, parent: null, group }],
        ),
    );
}

/** The operator's admin sidebar layout, as the Navigation editor saves it. */
export interface NavLayoutGroup {
    /** A built-in category, or `custom-<id>` for a group the operator added. */
    key: string;
    /** Rename; null keeps the built-in (translated) name. Required for custom groups. */
    label: string | null;
    /** Whether the group starts folded for admins who have not chosen. */
    collapsed: boolean;
    /** Entry ids (NavItem.id), in order. */
    items: string[];
}

export interface NavLayout {
    groups: NavLayoutGroup[];
    hidden: string[];
}

/**
 * Rearrange buildNav's groups by an operator layout.
 *
 * The layout only moves, renames and hides what buildNav already let through,
 * so it can never surface an entry the viewer's permissions or the feature
 * flags hide. Entries it doesn't mention (an extension installed since, a page
 * a panel update added) land in their default group, created at the end if
 * the layout dropped it. Header-less entries (Overview) stay on top, outside
 * the layout. `unhideable` ids ignore `hidden`, so the editor itself can't be
 * hidden away.
 */
export function applyNavLayout(
    groups: NavGroup[],
    layout: NavLayout | null | undefined,
    opts: { defaultCollapsed: string[]; unhideable: string[] },
): NavGroup[] {
    const withDefaults = (group: NavGroup): NavGroup => ({
        ...group,
        defaultCollapsed: group.category !== null && opts.defaultCollapsed.includes(group.category),
    });

    if (!layout) return groups.map(withDefaults);

    const head = groups.filter(g => g.category === null);
    const byId = new Map<string, NavItem>();
    for (const group of groups) {
        if (group.category === null) continue;
        for (const item of group.items) byId.set(item.id, item);
    }

    const hidden = new Set(layout.hidden.filter(id => !opts.unhideable.includes(id)));
    const placed = new Set<string>();

    const out: NavGroup[] = layout.groups.map(lg => ({
        category: lg.key,
        label: lg.label ?? undefined,
        defaultCollapsed: lg.collapsed,
        items: lg.items.flatMap(id => {
            const item = byId.get(id);
            if (!item || placed.has(id)) return [];
            placed.add(id);
            return hidden.has(id) ? [] : [item];
        }),
    }));

    for (const group of groups) {
        if (group.category === null) continue;
        for (const item of group.items) {
            if (placed.has(item.id) || hidden.has(item.id)) continue;
            let target = out.find(g => g.category === group.category);
            if (!target) {
                target = withDefaults({ category: group.category, items: [] });
                out.push(target);
            }
            target.items.push(item);
        }
    }

    return [...head, ...out.filter(g => g.items.length > 0)];
}
