import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { ChevronDown, ChevronUp, Eye, EyeOff, PanelLeft, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { m } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { SaveBar } from '@/components/ui/editorChrome';
import { navCategoryLabel, navItemLabel } from '@/components/shell/navLabels';
import { useFlags } from '@/state/flags';
import { useFlashes } from '@/state/flashes';
import { useAdminHeld } from '@/layouts/heldPermissions';
import { can } from '@/lib/can';
import { cn } from '@/lib/cn';
import { firstError } from '@/lib/apiError';
import { updateAdminNavigation } from '@/api/adminNavigation';
import { adminRoutes, ADMIN_NAV_DEFAULTS } from '@/routes/admin.routes';
import { buildNav, type NavItem, type NavLayout, type NavLayoutGroup } from '@/routes/nav';

// Every entry the admin sidebar can hold, whoever is looking and whatever is
// switched on: the layout is shared by all admins, so the editor must show
// (and preserve) entries this operator can't see or that are off right now.
// With no flags, buildNav skips every feature condition; '*' passes every
// permission. Overview has no group and stays pinned to the top.
const CATALOG = buildNav(adminRoutes, { flags: null, held: ['*'], basePath: '/admin' }).filter(g => g.category !== null);
const ITEMS = new Map<string, NavItem>(CATALOG.flatMap(g => g.items.map(i => [i.id, i] as const)));
const HOME = new Map<string, string>(CATALOG.flatMap(g => g.items.map(i => [i.id, g.category!] as const)));
const BUILT_IN = new Set(CATALOG.map(g => g.category!));

function defaultLayout(): NavLayout {
    return {
        groups: CATALOG.map(g => ({
            key: g.category!,
            label: null,
            collapsed: ADMIN_NAV_DEFAULTS.defaultCollapsed.includes(g.category!),
            items: g.items.map(i => i.id),
        })),
        hidden: [],
    };
}

// Put every known entry somewhere exactly once. Ids the catalog no longer has
// (an uninstalled extension) drop out; entries the layout never mentions go
// to their default group, which is recreated if the layout dropped it.
function place(layout: NavLayout): NavLayout {
    const seen = new Set<string>();
    const groups: NavLayoutGroup[] = layout.groups.map(g => ({
        ...g,
        items: g.items.filter(id => {
            if (!ITEMS.has(id) || seen.has(id)) return false;
            seen.add(id);
            return true;
        }),
    }));

    for (const [id, home] of HOME) {
        if (seen.has(id)) continue;
        let target = groups.find(g => g.key === home);
        if (!target) {
            target = { key: home, label: null, collapsed: ADMIN_NAV_DEFAULTS.defaultCollapsed.includes(home), items: [] };
            groups.push(target);
        }
        target.items.push(id);
    }

    return {
        groups,
        hidden: layout.hidden.filter(id => ITEMS.has(id) && !ADMIN_NAV_DEFAULTS.unhideable.includes(id)),
    };
}

const normalise = (layout: NavLayout | null | undefined) => place(layout ?? defaultLayout());
const same = (a: NavLayout, b: NavLayout) => JSON.stringify(a) === JSON.stringify(b);

// Blank renames mean "keep the built-in name", which the server stores as null.
const tidy = (layout: NavLayout): NavLayout => ({
    ...layout,
    groups: layout.groups.map(g => ({ ...g, label: g.label?.trim() || null })),
});

function swap<T>(list: T[], index: number, dir: -1 | 1): T[] {
    const target = index + dir;
    if (target < 0 || target >= list.length) return list;
    const next = list.slice();
    [next[index], next[target]] = [next[target]!, next[index]!];
    return next;
}

function IconButton({ label, onClick, disabled, children }: {
    label: string;
    onClick: () => void;
    disabled?: boolean;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-[var(--color-ink-muted)] transition-colors hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)] disabled:pointer-events-none disabled:opacity-30"
        >
            {children}
        </button>
    );
}

export default function NavigationSection() {
    const push = useFlashes(s => s.push);
    const everest = useFlags(s => s.everest);
    const site = useFlags(s => s.site);
    const setFlags = useFlags(s => s.set);
    const canEdit = can(useAdminHeld(), 'settings.update');

    const current = everest?.navigation?.admin ?? null;
    const [saved, setSaved] = useState<NavLayout>(() => normalise(current));
    const [draft, setDraft] = useState<NavLayout>(saved);
    const [saving, setSaving] = useState(false);

    // Entries whose feature or extension is off right now show an "Off" tag:
    // they keep their place but nobody sees them until switched back on.
    const live = useMemo(
        () => new Set(buildNav(adminRoutes, { flags: everest, held: ['*'], basePath: '/admin' }).flatMap(g => g.items.map(i => i.id))),
        [everest],
    );

    const dirty = !same(tidy(draft), saved);
    const hidden = new Set(draft.hidden);
    const unnamed = draft.groups.some(g => !BUILT_IN.has(g.key) && !g.label?.trim());

    const groupName = (g: NavLayoutGroup) => (BUILT_IN.has(g.key) ? navCategoryLabel(g.key) : g.label || m['admin.navigation.newGroup']());
    const groupOptions = draft.groups.map(g => ({ value: g.key, label: g.label?.trim() || groupName(g) }));

    const updateGroup = (key: string, patch: Partial<NavLayoutGroup>) =>
        setDraft(d => ({ ...d, groups: d.groups.map(g => (g.key === key ? { ...g, ...patch } : g)) }));

    const moveGroup = (index: number, dir: -1 | 1) => setDraft(d => ({ ...d, groups: swap(d.groups, index, dir) }));

    const moveItem = (key: string, index: number, dir: -1 | 1) =>
        setDraft(d => ({ ...d, groups: d.groups.map(g => (g.key === key ? { ...g, items: swap(g.items, index, dir) } : g)) }));

    const moveItemTo = (id: string, to: string) =>
        setDraft(d => ({
            ...d,
            groups: d.groups.map(g => {
                const without = g.items.filter(i => i !== id);
                return g.key === to ? { ...g, items: [...without, id] } : { ...g, items: without };
            }),
        }));

    const toggleHidden = (id: string) =>
        setDraft(d => ({ ...d, hidden: d.hidden.includes(id) ? d.hidden.filter(h => h !== id) : [...d.hidden, id] }));

    const addGroup = () =>
        setDraft(d => ({
            ...d,
            groups: [...d.groups, { key: `custom-${Math.random().toString(36).slice(2, 10)}`, label: '', collapsed: false, items: [] }],
        }));

    // A deleted group's entries go home rather than disappearing with it.
    const deleteGroup = (key: string) =>
        setDraft(d => place({ ...d, groups: d.groups.filter(g => g.key !== key) }));

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        if (saving || unnamed || !canEdit) return;
        setSaving(true);

        const layout = tidy(draft);
        try {
            // Matching the built-in layout saves null, so later panel updates
            // to the default order still reach this panel.
            const stored = await updateAdminNavigation(same(layout, normalise(null)) ? null : layout);
            const next = normalise(stored);
            if (everest) setFlags({ ...everest, navigation: { admin: stored } }, site);
            setSaved(next);
            setDraft(next);
            push({ type: 'success', message: m['admin.navigation.saved']() });
        } catch (err) {
            push({ type: 'error', message: firstError(err) ?? m['admin.navigation.saveError']() });
        } finally {
            setSaving(false);
        }
    };

    return (
        <form onSubmit={handleSubmit} className="flex flex-col gap-6">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0">
                    <h1 className="flex items-center gap-2 text-xl font-semibold text-[var(--color-ink)]">
                        <PanelLeft className="h-5 w-5 text-[var(--brand)]" />
                        {m['admin.navigation.title']()}
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm text-[var(--color-ink-muted)]">{m['admin.navigation.subtitle']()}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button type="button" variant="ghost" size="sm" onClick={() => setDraft(normalise(null))} disabled={!canEdit || same(tidy(draft), normalise(null))}>
                        <RotateCcw className="h-4 w-4" /> {m['admin.navigation.restoreDefault']()}
                    </Button>
                    <Button type="button" variant="outline" size="sm" onClick={addGroup} disabled={!canEdit || draft.groups.length >= 20}>
                        <Plus className="h-4 w-4" /> {m['admin.navigation.addGroup']()}
                    </Button>
                </div>
            </div>

            <p className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-2)]/60 px-4 py-3 text-sm text-[var(--color-ink-muted)]">
                {m['admin.navigation.hiddenNote']()}
            </p>

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                {draft.groups.map((group, gi) => {
                    const builtIn = BUILT_IN.has(group.key);
                    return (
                        <section
                            key={group.key}
                            className="flex flex-col gap-3 rounded-[var(--radius-card)] border border-[var(--color-border-strong)] bg-[var(--color-surface)]/60 p-4"
                        >
                            <div className="flex items-center gap-2">
                                <Input
                                    id={`nav-group-${group.key}`}
                                    aria-label={m['admin.navigation.groupName']()}
                                    value={group.label ?? ''}
                                    placeholder={builtIn ? navCategoryLabel(group.key) : m['admin.navigation.newGroupPlaceholder']()}
                                    maxLength={40}
                                    invalid={!builtIn && !group.label?.trim()}
                                    disabled={!canEdit}
                                    onChange={e => updateGroup(group.key, { label: e.target.value })}
                                    className="h-9"
                                />
                                <IconButton label={m['admin.navigation.moveUp']()} onClick={() => moveGroup(gi, -1)} disabled={!canEdit || gi === 0}>
                                    <ChevronUp className="h-4 w-4" />
                                </IconButton>
                                <IconButton label={m['admin.navigation.moveDown']()} onClick={() => moveGroup(gi, 1)} disabled={!canEdit || gi === draft.groups.length - 1}>
                                    <ChevronDown className="h-4 w-4" />
                                </IconButton>
                                {!builtIn && (
                                    <IconButton label={m['admin.navigation.deleteGroup']()} onClick={() => deleteGroup(group.key)} disabled={!canEdit}>
                                        <Trash2 className="h-4 w-4" />
                                    </IconButton>
                                )}
                            </div>
                            <label className="flex items-center justify-between gap-3 text-sm text-[var(--color-ink-muted)]">
                                <span>{m['admin.navigation.startsFolded']()}</span>
                                <Switch checked={group.collapsed} disabled={!canEdit} onChange={v => updateGroup(group.key, { collapsed: v })} />
                            </label>

                            {group.items.length === 0 ? (
                                <p className="rounded-lg border border-dashed border-[var(--color-border)] px-3 py-4 text-center text-sm text-[var(--color-ink-faint)]">
                                    {m['admin.navigation.emptyGroup']()}
                                </p>
                            ) : (
                                <ul className="flex flex-col divide-y divide-[var(--color-border)] rounded-lg border border-[var(--color-border)]">
                                    {group.items.map((id, ii) => {
                                        const item = ITEMS.get(id);
                                        if (!item) return null;
                                        const isHidden = hidden.has(id);
                                        const locked = ADMIN_NAV_DEFAULTS.unhideable.includes(id);
                                        const extension = id.startsWith('ext:');

                                        return (
                                            <li key={id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                                {item.icon && <item.icon className={cn('h-4 w-4 shrink-0', isHidden ? 'text-[var(--color-ink-faint)]' : 'text-[var(--color-ink-muted)]')} />}
                                                <span className={cn('min-w-0 flex-1 truncate text-sm', isHidden ? 'text-[var(--color-ink-faint)] line-through' : 'text-[var(--color-ink)]')}>
                                                    {navItemLabel(item)}
                                                </span>
                                                {extension && (
                                                    <span className="rounded border border-[var(--color-border)] px-1.5 text-[10px] font-medium uppercase tracking-wide text-[var(--color-ink-faint)]">
                                                        {m['admin.navigation.extensionTag']()}
                                                    </span>
                                                )}
                                                {!live.has(id) && (
                                                    <span title={m['admin.navigation.offHint']()} className="rounded bg-[var(--color-surface-2)] px-1.5 text-[10px] font-medium uppercase tracking-wide text-[var(--color-ink-faint)]">
                                                        {m['admin.navigation.offTag']()}
                                                    </span>
                                                )}
                                                <div className="flex items-center gap-1">
                                                    <Select
                                                        id={`nav-move-${id}`}
                                                        value={group.key}
                                                        options={groupOptions}
                                                        disabled={!canEdit || draft.groups.length < 2}
                                                        onChange={to => moveItemTo(id, to)}
                                                        className="h-8 w-36 text-xs"
                                                    />
                                                    <IconButton label={m['admin.navigation.moveUp']()} onClick={() => moveItem(group.key, ii, -1)} disabled={!canEdit || ii === 0}>
                                                        <ChevronUp className="h-4 w-4" />
                                                    </IconButton>
                                                    <IconButton label={m['admin.navigation.moveDown']()} onClick={() => moveItem(group.key, ii, 1)} disabled={!canEdit || ii === group.items.length - 1}>
                                                        <ChevronDown className="h-4 w-4" />
                                                    </IconButton>
                                                    <IconButton
                                                        label={locked ? m['admin.navigation.lockedHint']() : isHidden ? m['admin.navigation.show']() : m['common.actions.hide']()}
                                                        onClick={() => toggleHidden(id)}
                                                        disabled={!canEdit || locked}
                                                    >
                                                        {isHidden ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                                                    </IconButton>
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </section>
                    );
                })}
            </div>

            {canEdit && (
                <SaveBar
                    dirty={dirty}
                    saving={saving}
                    onDiscard={() => setDraft(saved)}
                    blockedReason={unnamed ? m['admin.navigation.unnamedGroup']() : null}
                />
            )}
        </form>
    );
}
