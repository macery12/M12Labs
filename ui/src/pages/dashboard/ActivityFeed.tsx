import { m } from '@/i18n';
import { useQuery } from '@tanstack/react-query';
import { History } from 'lucide-react';
import { getAccountActivity } from '@/api/activity';
import { timeAgo } from '@/lib/format';

// Turn an event key like 'auth:fail' or 'user:account.email-changed' into prose.
function prettyEvent(event: string): string {
    const tail = event.split(':').pop() ?? event;
    return tail
        .replace(/[._-]/g, ' ')
        .replace(/\b\w/g, c => c.toUpperCase());
}

export function ActivityFeed() {
    const { data: entries, isLoading } = useQuery({
        queryKey: ['account-activity'],
        queryFn: getAccountActivity,
    });

    return (
        <section className="flex flex-col gap-3">
            <h2 className="flex items-center gap-2 text-sm font-semibold text-[var(--color-ink-muted)]">
                <History className="h-4 w-4" /> {m['dashboard.recentActivity']()}
            </h2>
            <div className="rounded-2xl border border-[var(--color-border-strong)] bg-[var(--color-surface)]/70 p-2">
                {isLoading && <p className="px-3 py-4 text-sm text-[var(--color-ink-faint)]">{m['common.states.loading']()}</p>}
                {!isLoading && (!entries || entries.length === 0) && (
                    <p className="px-3 py-4 text-sm text-[var(--color-ink-faint)]">{m['dashboard.noActivity']()}</p>
                )}
                {entries?.map((entry, i) => (
                    <div
                        key={entry.id}
                        className="flex items-start gap-3 px-3 py-2.5"
                        style={{ borderTop: i === 0 ? undefined : '1px solid var(--color-border)' }}
                    >
                        <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--brand)]" />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm text-[var(--color-ink)]">
                                {entry.description || prettyEvent(entry.event)}
                            </p>
                            <p className="text-xs text-[var(--color-ink-faint)]">
                                {timeAgo(entry.timestamp)}
                                {entry.ip ? ` · ${entry.ip}` : ''}
                            </p>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
