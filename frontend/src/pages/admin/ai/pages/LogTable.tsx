import { Ban, Check, LoaderCircle, Pause, X, Zap } from 'lucide-react';
import { m } from '@/i18n/messages';
import { cn } from '@/lib/cn';
import { DataTable, type DataTableColumn } from '@/extensions-sdk';
import type { AiLogEntry } from '@/api/adminAi';
import { sourceChip, sourceLabel, sourceTone } from '../sources';

// Shared request-log table used by the overview (recent 10) and the Logs tab
// (filtered, up to 500). Cached responses carry a lightning badge — they cost
// no tokens and return near-instantly.
export function LogTable({ logs, loading }: { logs: AiLogEntry[]; loading: boolean }) {
    const columns: DataTableColumn<AiLogEntry>[] = [
        {
            id: 'time',
            header: m['admin.ai.logs.time'](),
            cellClassName: 'whitespace-nowrap font-mono text-[var(--color-ink-faint)]',
            cell: log => (
                <>
                    {new Date(log.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                    <span className="ml-1.5 opacity-60">
                        {new Date(log.created_at).toLocaleDateString([], { month: 'short', day: 'numeric' })}
                    </span>
                </>
            ),
        },
        {
            id: 'user',
            header: m['admin.ai.logs.user'](),
            cellClassName: 'text-[var(--color-ink)]',
            cell: log => log.username,
        },
        {
            id: 'server',
            header: m['admin.ai.logs.server'](),
            cellClassName: 'max-w-[10rem] truncate text-[var(--color-ink-muted)]',
            cell: log => log.server_name ?? '—',
        },
        {
            id: 'model',
            header: m['admin.ai.logs.model'](),
            cellClassName: 'font-mono text-[var(--color-ink-muted)]',
            cell: log => log.model,
        },
        {
            id: 'source',
            header: m['admin.ai.logs.source'](),
            cell: log => (
                <span
                    className={cn(
                        'rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                        sourceChip[sourceTone(log.source)],
                    )}
                >
                    {sourceLabel(log.source)}
                </span>
            ),
        },
        {
            id: 'tokens',
            header: m['admin.ai.logs.tokens'](),
            cellClassName: 'font-mono tabular-nums text-[var(--color-ink-muted)]',
            cell: log => log.total_tokens ?? '—',
        },
        {
            id: 'latency',
            header: m['admin.ai.logs.latency'](),
            cellClassName: 'whitespace-nowrap font-mono tabular-nums text-[var(--color-ink-muted)]',
            cell: log => (
                <>
                    {log.latency_ms != null ? `${log.latency_ms}ms` : '—'}
                    {log.cached && (
                        <span
                            title={m['admin.ai.logs.cachedHint']()}
                            className="ml-1.5 inline-flex items-center gap-0.5 rounded bg-[var(--color-accent)]/15 px-1 py-0.5 text-[10px] font-semibold text-[var(--color-accent)]"
                        >
                            <Zap className="h-2.5 w-2.5" />
                            {m['admin.ai.logs.cached']()}
                        </span>
                    )}
                </>
            ),
        },
        {
            id: 'status',
            header: m['admin.ai.logs.status'](),
            cell: log => <StatusIcon log={log} />,
        },
    ];

    return (
        <DataTable
            columns={columns}
            rows={logs}
            rowKey={log => log.id}
            loading={loading}
            loadingLabel={m['admin.ai.logs.title']()}
            empty={m['admin.ai.logs.empty']()}
            virtualize={logs.length > 50}
            maxHeight={logs.length > 50 ? 520 : undefined}
        />
    );
}

function StatusIcon({ log }: { log: AiLogEntry }) {
    const labels = {
        success: m['admin.ai.logs.statusSuccess'](),
        error: m['admin.ai.logs.statusError'](),
        running: m['admin.ai.logs.statusRunning'](),
        suspended: m['admin.ai.logs.statusSuspended'](),
        cancelled: m['admin.ai.logs.statusCancelled'](),
    };
    const label = log.status === 'error' && log.error_message
        ? `${labels.error}: ${log.error_message}`
        : labels[log.status];

    return (
        <span title={label} aria-label={label}>
            {log.status === 'success' && <Check className="h-3.5 w-3.5 text-[var(--color-accent)]" />}
            {log.status === 'error' && <X className="h-3.5 w-3.5 text-[var(--color-danger)]" />}
            {log.status === 'running' && <LoaderCircle className="h-3.5 w-3.5 animate-spin text-[var(--brand)]" />}
            {log.status === 'suspended' && <Pause className="h-3.5 w-3.5 text-[var(--color-warning)]" />}
            {log.status === 'cancelled' && <Ban className="h-3.5 w-3.5 text-[var(--color-ink-faint)]" />}
        </span>
    );
}
