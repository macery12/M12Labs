import { AlertTriangle, Lock, RotateCcw, SearchX, type LucideIcon } from 'lucide-react';
import { m } from '@/i18n/messages';
import { cn } from '@/lib/cn';
import { errorStatus, firstError } from '@/lib/apiError';
import { Button } from '@/components/ui/Button';

// Shared "nothing here" and "couldn't load" blocks. Pages used to print a
// single grey line: "No orders match your filters" for someone with no filters
// set, or "Couldn't load … please try again" for a 403 that no retry would fix.

export interface EmptyStateProps {
    icon?: LucideIcon;
    title: string;
    body?: string;
    /** The next step: a button or link. An empty state without one is a dead end. */
    action?: React.ReactNode;
    tone?: 'muted' | 'danger';
    className?: string;
}

export function EmptyState({ icon: Icon, title, body, action, tone = 'muted', className }: EmptyStateProps) {
    return (
        <div className={cn('flex flex-col items-center px-6 py-12 text-center', className)}>
            {Icon && (
                <div
                    className={cn(
                        'mb-3 flex h-11 w-11 items-center justify-center rounded-lg border',
                        tone === 'danger'
                            ? 'border-[var(--color-danger)]/40 bg-[var(--color-danger)]/10 text-[var(--color-danger)]'
                            : 'border-[var(--color-border)] bg-[var(--color-surface-2)] text-[var(--color-ink-faint)]',
                    )}
                >
                    <Icon className="h-5 w-5" />
                </div>
            )}
            <p className="text-sm font-medium text-[var(--color-ink)]">{title}</p>
            {body && <p className="mt-1 max-w-md text-sm text-[var(--color-ink-muted)]">{body}</p>}
            {action && <div className="mt-4 flex flex-wrap items-center justify-center gap-2">{action}</div>}
        </div>
    );
}

/**
 * "No results" for a filtered list, with a way back. Only for when a filter is
 * actually set; an unfiltered empty list gets an EmptyState with a real next step.
 */
export function NoMatches({ title, onClear, className }: { title?: string; onClear: () => void; className?: string }) {
    return (
        <EmptyState
            icon={SearchX}
            title={title ?? m['common.empty.noMatches']()}
            action={
                <Button variant="outline" size="sm" onClick={onClear}>
                    {m['common.empty.clearFilters']()}
                </Button>
            }
            className={className}
        />
    );
}

/**
 * A failed load, worded by what actually went wrong. A 403 says why and offers
 * no retry, since retrying can't help. A 404 says the thing is gone. A server
 * error or a lost connection offers Try again. Other 4xx show the server's
 * message.
 */
export function ErrorState({
    error,
    onRetry,
    retrying,
    className,
}: {
    error: unknown;
    onRetry?: () => void;
    retrying?: boolean;
    className?: string;
}) {
    const status = errorStatus(error);

    if (status === 403) {
        return (
            <EmptyState
                icon={Lock}
                title={m['common.empty.forbiddenTitle']()}
                body={firstError(error) ?? m['common.empty.forbiddenBody']()}
                className={className}
            />
        );
    }

    if (status === 404) {
        return <EmptyState icon={SearchX} title={m['common.empty.notFoundTitle']()} className={className} />;
    }

    const transient = status === null || status >= 500 || status === 429;
    return (
        <EmptyState
            icon={AlertTriangle}
            tone="danger"
            title={m['common.empty.loadFailedTitle']()}
            body={transient ? m['common.empty.loadFailedBody']() : (firstError(error) ?? m['common.states.genericError']())}
            action={
                transient && onRetry ? (
                    <Button variant="outline" size="sm" onClick={onRetry} disabled={retrying}>
                        <RotateCcw className="h-4 w-4" />
                        {m['common.actions.retry']()}
                    </Button>
                ) : undefined
            }
            className={className}
        />
    );
}
