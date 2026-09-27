import { useState } from 'react';
import { m, td } from '@/i18n/messages';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';
import type { WebhookEvent } from '@/api/webhooks';
import { activityEventLabel } from '@/lib/activity';

interface Props {
    category: string;
    events: WebhookEvent[];
    /** A search is active: show the matches instead of a folded header. */
    searching: boolean;
    onToggleEvent: (id: number, enabled: boolean) => void;
    onToggleCategory: (events: WebhookEvent[], enabled: boolean) => Promise<void>;
}

// Seeded events all carry "The event admin:x:y was executed" as a description,
// which says nothing the key doesn't. Treat that as no description.
const PLACEHOLDER = /^The event \S+ was executed$/;

// One collapsible category group. The header shows the enabled/total ratio and
// per-category enable/disable-all controls; the body is a grid of toggle cards.
// Groups start folded: 59 open cards made a 4,500px page where the counts in
// the headers are what an admin scans for.
export function EventCategorySection({ category, events, searching, onToggleEvent, onToggleCategory }: Props) {
    const [folded, setFolded] = useState(true);
    const open = searching || !folded;
    const [busy, setBusy] = useState(false);

    const enabledCount = events.filter(e => e.enabled).length;
    const total = events.length;
    const desc = td(`admin.webhooks.categoryDesc.${category}`, m['admin.webhooks.category.genericDesc']());

    const bulk = async (enabled: boolean) => {
        setBusy(true);
        try {
            await onToggleCategory(events, enabled);
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="overflow-hidden rounded-[var(--radius-card)] border border-[var(--color-border)]">
            <div className="flex flex-wrap items-center justify-between gap-3 bg-[var(--color-surface)]/70 px-4 py-3">
                <button
                    type="button"
                    onClick={() => setFolded(open)}
                    aria-expanded={open}
                    className="flex min-w-0 items-center gap-3 text-left"
                >
                    {open ? (
                        <ChevronDown className="h-4 w-4 shrink-0 text-[var(--color-ink-faint)]" />
                    ) : (
                        <ChevronRight className="h-4 w-4 shrink-0 text-[var(--color-ink-faint)]" />
                    )}
                    <div className="min-w-0">
                        <h3 className="truncate text-sm font-semibold capitalize text-[var(--color-ink)]">
                            {category.replace(/-/g, ' ')}
                        </h3>
                        <p className="truncate text-xs text-[var(--color-ink-muted)]">{desc}</p>
                    </div>
                </button>

                <div className="flex items-center gap-3">
                    <span className="text-xs font-medium text-[var(--color-ink-muted)]">
                        {m['admin.webhooks.category.active']({ enabled: enabledCount, total })}
                    </span>
                    <div className="flex gap-1.5">
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => bulk(true)}
                            disabled={busy || enabledCount === total}
                        >
                            {m['admin.webhooks.events.enableAll']()}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => bulk(false)}
                            disabled={busy || enabledCount === 0}
                        >
                            {m['admin.webhooks.events.disableAll']()}
                        </Button>
                    </div>
                </div>
            </div>

            {open && (
                <div className="grid gap-3 bg-[var(--color-canvas)]/40 p-4 md:grid-cols-2 xl:grid-cols-3">
                    {events.map(event => (
                        // The whole card is the toggle control — clicking anywhere
                        // flips the event. The switch is presentational (aria-hidden)
                        // so there's a single control with `aria-pressed` state.
                        <button
                            key={event.id}
                            type="button"
                            role="switch"
                            aria-checked={event.enabled}
                            aria-label={activityEventLabel(event.key)}
                            title={event.key}
                            onClick={() => onToggleEvent(event.id, !event.enabled)}
                            className={cn(
                                'flex items-start justify-between gap-3 rounded-lg border p-3.5 text-left transition-colors',
                                'focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]/60',
                                event.enabled
                                    ? 'border-[var(--brand)]/40 bg-[var(--brand-soft)] hover:border-[var(--brand)]/60'
                                    : 'border-[var(--color-border)] bg-[var(--color-surface)]/60 hover:bg-[var(--color-surface-2)]',
                            )}
                        >
                            <div className="min-w-0">
                                <h4 className="text-sm font-medium text-[var(--color-ink)]">
                                    {activityEventLabel(event.key)}
                                </h4>
                                {event.description && !PLACEHOLDER.test(event.description) && (
                                    <p className="mt-0.5 text-xs text-[var(--color-ink-muted)]">{event.description}</p>
                                )}
                                {/* The key is what arrives in the payload's "event" field. */}
                                <p className="mt-1 font-mono text-[11px] text-[var(--color-ink-faint)]">
                                    {m['admin.webhooks.events.payloadKey']({ key: event.key })}
                                </p>
                            </div>
                            <div className="flex flex-col items-end gap-1.5">
                                <span
                                    aria-hidden
                                    className={cn(
                                        'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border transition-colors duration-150',
                                        event.enabled
                                            ? 'border-transparent bg-[var(--brand)]'
                                            : 'border-[var(--color-border-strong)] bg-[var(--color-surface-2)]',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'inline-block h-3.5 w-3.5 transform rounded-full bg-[var(--color-brand-ink)] shadow-sm transition-transform duration-150',
                                            event.enabled ? 'translate-x-4' : 'translate-x-1',
                                        )}
                                    />
                                </span>
                                <span
                                    className={cn(
                                        'text-[11px] font-medium',
                                        event.enabled
                                            ? 'text-[var(--brand)]'
                                            : 'text-[var(--color-ink-faint)]',
                                    )}
                                >
                                    {event.enabled ? m['common.states.enabled']() : m['common.states.disabled']()}
                                </span>
                            </div>
                        </button>
                    ))}
                </div>
            )}
        </section>
    );
}
