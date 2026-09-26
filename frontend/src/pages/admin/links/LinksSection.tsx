import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Link2, Search, Eye, EyeOff, ChevronUp, ChevronDown } from 'lucide-react';
import { m } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import { getLinks, linkHost, reorderLinks, type CustomLink } from '@/api/adminLinks';
import LinkEditor from './LinkEditor';

type Selection = { mode: 'edit'; id: number } | { mode: 'new' } | null;

function MoveButton({
    label,
    disabled,
    onClick,
    children,
}: {
    label: string;
    disabled: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-label={label}
            title={label}
            className="rounded p-0.5 text-[var(--color-ink-faint)] transition-colors hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)] disabled:pointer-events-none disabled:opacity-30"
        >
            {children}
        </button>
    );
}

function RailRow({
    link,
    active,
    onClick,
    move,
}: {
    link: CustomLink;
    active: boolean;
    onClick: () => void;
    // Absent while a search filter is active: moving within a filtered view
    // would reorder against rows the operator can't see.
    move?: { up: (() => void) | null; down: (() => void) | null; busy: boolean };
}) {
    const Visibility = link.visible ? Eye : EyeOff;
    return (
        <div
            className={cn(
                'flex items-center border-l-2 transition-colors',
                active
                    ? 'border-[var(--brand)] bg-[var(--brand-soft)]'
                    : 'border-transparent hover:bg-[var(--color-surface-2)]',
            )}
        >
            <button
                type="button"
                onClick={onClick}
                className="flex min-w-0 flex-1 items-center gap-3 px-3 py-2.5 text-left"
            >
                <Visibility
                    className={cn(
                        'h-4 w-4 shrink-0',
                        link.visible ? 'text-[var(--color-accent)]' : 'text-[var(--color-ink-faint)]',
                    )}
                    aria-label={link.visible ? m['admin.links.visible']() : m['admin.links.hidden']()}
                />
                <span className="min-w-0 flex-1">
                    <span
                        className={cn(
                            'block truncate text-sm font-medium',
                            link.visible ? 'text-[var(--color-ink)]' : 'text-[var(--color-ink-faint)]',
                        )}
                    >
                        {link.name}
                    </span>
                    <span className="block truncate font-mono text-xs text-[var(--color-ink-faint)]">
                        {linkHost(link.url)}
                    </span>
                </span>
            </button>
            {move && (
                <div className="flex shrink-0 flex-col pr-2">
                    <MoveButton
                        label={m['admin.links.moveUp']({ name: link.name })}
                        disabled={!move.up || move.busy}
                        onClick={() => move.up?.()}
                    >
                        <ChevronUp className="h-3.5 w-3.5" />
                    </MoveButton>
                    <MoveButton
                        label={m['admin.links.moveDown']({ name: link.name })}
                        disabled={!move.down || move.busy}
                        onClick={() => move.down?.()}
                    >
                        <ChevronDown className="h-3.5 w-3.5" />
                    </MoveButton>
                </div>
            )}
        </div>
    );
}

// Admin Links — master–detail workspace for the operator-defined external links
// shown to end users in the dashboard and server sidebars. Left rail lists every
// link in display order with its visibility state + host, and moves links up or
// down; the right pane edits the selected link or creates a new one.
export default function LinksSection() {
    const { data: links, isLoading, isError } = useQuery({
        queryKey: ['admin', 'links'],
        queryFn: getLinks,
    });

    const [selection, setSelection] = useState<Selection>(null);
    const [query, setQuery] = useState('');
    const push = useFlashes(s => s.push);
    const qc = useQueryClient();

    // Optimistic: the rail reorders immediately and rolls back if the server
    // refuses (e.g. another admin added a link since this list loaded).
    const reorderMutation = useMutation({
        mutationFn: (ordered: CustomLink[]) => reorderLinks(ordered.map(l => l.id)),
        onMutate: async ordered => {
            await qc.cancelQueries({ queryKey: ['admin', 'links'] });
            const previous = qc.getQueryData<CustomLink[]>(['admin', 'links']);
            qc.setQueryData(['admin', 'links'], ordered);
            return { previous };
        },
        onError: (err, _ordered, ctx) => {
            if (ctx?.previous) qc.setQueryData(['admin', 'links'], ctx.previous);
            push({ type: 'error', message: firstError(err) ?? m['common.states.genericError']() });
        },
        onSettled: () => {
            qc.invalidateQueries({ queryKey: ['admin', 'links'] });
            qc.invalidateQueries({ queryKey: ['links', 'visible'] });
        },
    });

    const move = (index: number, delta: -1 | 1) => {
        if (!links) return;
        const ordered = [...links];
        const [row] = ordered.splice(index, 1);
        ordered.splice(index + delta, 0, row!);
        reorderMutation.mutate(ordered);
    };

    // Auto-select the first link once loaded so the detail pane is never blank
    // when links exist; fall back to the create form on an empty list.
    useEffect(() => {
        if (!links || selection !== null) return;
        // eslint-disable-next-line react-hooks/set-state-in-effect -- intentional effect: syncs state to prop/query/filter changes
        setSelection(links.length > 0 ? { mode: 'edit', id: links[0]!.id } : { mode: 'new' });
    }, [links, selection]);

    // A stale edit-selection (e.g. after a delete) collapses to the first link or new.
    useEffect(() => {
        if (selection?.mode === 'edit' && links && !links.some(l => l.id === selection.id)) {
            // eslint-disable-next-line react-hooks/set-state-in-effect -- intentional effect: syncs state to prop/query/filter changes
            setSelection(links.length > 0 ? { mode: 'edit', id: links[0]!.id } : { mode: 'new' });
        }
    }, [selection, links]);

    const selectedLink = useMemo(() => {
        if (!selection || selection.mode !== 'edit' || !links) return null;
        return links.find(l => l.id === selection.id) ?? null;
    }, [selection, links]);

    // Mirrors V1's search: name or URL, ignored under two characters.
    const searching = query.trim().length >= 2;
    const filtered = useMemo(() => {
        if (!links) return [];
        const q = query.trim().toLowerCase();
        if (!searching) return links;
        return links.filter(l => l.name.toLowerCase().includes(q) || l.url.toLowerCase().includes(q));
    }, [links, query, searching]);

    const editorKey = selection?.mode === 'edit' ? `edit-${selection.id}` : 'new';

    return (
        <div className="flex flex-col gap-6">
            <header className="flex items-start justify-between gap-4">
                <div>
                    <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
                        <Link2 className="h-6 w-6 text-[var(--color-ink-muted)]" />
                        {m['admin.links.title']()}
                    </h1>
                    <p className="mt-1 text-sm text-[var(--color-ink-muted)]">{m['admin.links.subtitle']()}</p>
                </div>
                <Button size="sm" onClick={() => setSelection({ mode: 'new' })}>
                    <Plus className="h-4 w-4" />
                    {m['admin.links.newLink']()}
                </Button>
            </header>

            <div className="flex flex-col gap-6 lg:flex-row lg:gap-8">
                {/* Rail */}
                <aside className="w-full shrink-0 lg:w-72">
                    <div className="relative mb-3">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-ink-faint)]" />
                        <Input
                            value={query}
                            onChange={e => setQuery(e.target.value)}
                            placeholder={m['admin.links.searchPlaceholder']()}
                            className="pl-9"
                        />
                    </div>
                    <div
                        className="overflow-hidden border border-[var(--color-border)] bg-[var(--color-surface)]"
                        style={{ borderRadius: 'var(--radius-card)' }}
                    >
                        {isLoading ? (
                            <div className="flex items-center justify-center py-12">
                                <Spinner className="h-5 w-5" />
                            </div>
                        ) : isError ? (
                            <p className="px-4 py-8 text-center text-sm text-[var(--color-danger)]">
                                {m['common.states.genericError']()}
                            </p>
                        ) : !links || links.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm text-[var(--color-ink-faint)]">
                                {m['admin.links.empty']()}
                            </p>
                        ) : filtered.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm text-[var(--color-ink-faint)]">
                                {m['admin.links.noMatches']()}
                            </p>
                        ) : (
                            <div className="flex flex-col divide-y divide-[var(--color-border)]">
                                {filtered.map((link, index) => (
                                    <RailRow
                                        key={link.id}
                                        link={link}
                                        active={selection?.mode === 'edit' && selection.id === link.id}
                                        onClick={() => setSelection({ mode: 'edit', id: link.id })}
                                        move={
                                            searching
                                                ? undefined
                                                : {
                                                      up: index > 0 ? () => move(index, -1) : null,
                                                      down: index < filtered.length - 1 ? () => move(index, 1) : null,
                                                      busy: reorderMutation.isPending,
                                                  }
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                </aside>

                {/* Detail */}
                <div className="min-w-0 flex-1">
                    {selection === null ? (
                        <div className="flex items-center justify-center py-20 text-sm text-[var(--color-ink-faint)]">
                            <Spinner className="h-5 w-5" />
                        </div>
                    ) : (
                        <LinkEditor
                            key={editorKey}
                            link={selectedLink}
                            onSaved={id => setSelection({ mode: 'edit', id })}
                            onDeleted={() =>
                                setSelection(
                                    links && links.length > 1
                                        ? { mode: 'edit', id: links.find(l => l.id !== selectedLink?.id)!.id }
                                        : { mode: 'new' },
                                )
                            }
                            onCancel={() =>
                                setSelection(
                                    links && links.length > 0 ? { mode: 'edit', id: links[0]!.id } : { mode: 'new' },
                                )
                            }
                        />
                    )}
                </div>
            </div>
        </div>
    );
}
