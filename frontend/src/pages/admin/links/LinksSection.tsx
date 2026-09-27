import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Link2, Search, Eye, EyeOff } from 'lucide-react';
import { m } from '@/i18n/messages';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import { getLinks, linkHost, reorderLinks, type CustomLink } from '@/api/adminLinks';
import { DragHandle, dropIndicator, useDragReorder } from '@/components/ui/DragReorder';
import LinkEditor from './LinkEditor';

type Selection = { mode: 'edit'; id: number } | { mode: 'new' } | null;

type Reorder = ReturnType<typeof useDragReorder>;

function RailRow({
    link,
    active,
    onClick,
    index,
    count,
    reorder,
    onMove,
    busy,
}: {
    link: CustomLink;
    active: boolean;
    onClick: () => void;
    index: number;
    count: number;
    // Absent while a search filter is active: moving within a filtered view
    // would reorder against rows the operator can't see.
    reorder?: Reorder;
    onMove: (from: number, to: number) => void;
    busy: boolean;
}) {
    const Visibility = link.visible ? Eye : EyeOff;
    return (
        <div
            {...(reorder?.rowProps(index) ?? {})}
            className={cn(
                'flex items-center border-l-2 transition-colors',
                active
                    ? 'border-[var(--brand)] bg-[var(--brand-soft)]'
                    : 'border-transparent hover:bg-[var(--color-surface-2)]',
                reorder && reorder.dragIndex === index && 'opacity-50',
                reorder && dropIndicator(reorder.dragIndex, reorder.overIndex, index),
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
            {/* The old 14px chevrons were hard to hit; a grip (drag, or arrow
                keys when focused) replaces them, with larger buttons on touch. */}
            {reorder && (
                <div className="pr-1.5">
                    <DragHandle
                        label={m['admin.links.reorder']({ name: link.name })}
                        hint={m['admin.links.reorderHint']()}
                        upLabel={m['admin.links.moveUp']({ name: link.name })}
                        downLabel={m['admin.links.moveDown']({ name: link.name })}
                        index={index}
                        count={count}
                        disabled={busy}
                        handleProps={reorder.handleProps(index, count)}
                        onMove={onMove}
                    />
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

    const move = (from: number, to: number) => {
        if (!links || from === to || to < 0 || to >= links.length) return;
        const ordered = [...links];
        const [row] = ordered.splice(from, 1);
        ordered.splice(to, 0, row!);
        reorderMutation.mutate(ordered);
    };
    const reorder = useDragReorder(move);

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
                                        index={index}
                                        count={filtered.length}
                                        reorder={searching ? undefined : reorder}
                                        onMove={move}
                                        busy={reorderMutation.isPending}
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
