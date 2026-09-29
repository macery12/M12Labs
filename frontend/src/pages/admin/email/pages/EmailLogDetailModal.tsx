import { m } from '@/i18n/messages';
import { useQuery } from '@tanstack/react-query';
import { Copy } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { useFlashes } from '@/state/flashes';
import { getEmailLog } from '@/api/email';
import { StatusChip } from '../parts';
import { formatDateTime } from '@/lib/format';

// Read-only detail view for a single email log entry: metadata, error, and
// each attempt with the provider that made it.
export function EmailLogDetailModal({ logId, onClose }: { logId: number; onClose: () => void }) {
    const push = useFlashes(s => s.push);
    const { data, isLoading } = useQuery({
        queryKey: ['admin', 'email', 'log', logId],
        queryFn: () => getEmailLog(logId),
    });

    const copyBundle = () => {
        if (!data) return;
        const bundle = {
            log: data.log,
            retry_history: data.retry_history,
            timestamp: new Date().toISOString(),
        };
        navigator.clipboard.writeText(JSON.stringify(bundle, null, 2));
        push({ type: 'success', message: m['admin.email.detail.copied']() });
    };

    const fmt = (d: string) => formatDateTime(d);

    return (
        <Modal
            open
            onClose={onClose}
            size="lg"
            title={m['admin.email.detail.title']({ id: logId })}
            footer={
                <>
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        {m['common.actions.close']()}
                    </Button>
                    <Button variant="secondary" size="sm" onClick={copyBundle} disabled={!data}>
                        <Copy className="h-4 w-4" />
                        {m['admin.email.detail.copyBundle']()}
                    </Button>
                </>
            }
        >
            {isLoading || !data ? (
                <div className="flex items-center justify-center py-12">
                    <Spinner className="h-6 w-6" />
                </div>
            ) : (
                <div className="flex flex-col gap-6">
                    <Section title={m['ui.labels.basicInformation']()}>
                        <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                            <Item label={m['ui.labels.status']()}>
                                <StatusChip status={data.log.status} />
                            </Item>
                            <Item label={m['admin.email.detail.sentAt']()}>{fmt(data.log.created_at)}</Item>
                            <Item label={m['ui.labels.recipient']()}>{data.log.to}</Item>
                            <Item label={m['ui.labels.user']()}>
                                {data.log.user ? `${data.log.user.username} (${data.log.user.email})` : '—'}
                            </Item>
                            <Item label={m['ui.labels.subject']()}>{data.log.subject}</Item>
                            <Item label={m['admin.email.detail.template']()}>
                                <code className="rounded bg-[var(--color-surface-2)] px-1.5 py-0.5 text-[11px]">
                                    {data.log.template_key || m['admin.email.activity.custom']()}
                                </code>
                            </Item>
                            <Item label={m['ui.labels.provider']()}>{data.log.provider || '—'}</Item>
                            <Item label={m['ui.labels.attempts']()}>{data.log.attempt_count}</Item>
                            <Item label={m['admin.email.detail.messageId']()} mono>
                                {data.log.message_id || '—'}
                            </Item>
                            <Item label={m['admin.email.detail.correlationId']()} mono>
                                {data.log.correlation_id || '—'}
                            </Item>
                            {data.log.duration_ms != null && (
                                <Item label={m['admin.email.detail.duration']()}>{data.log.duration_ms}ms</Item>
                            )}
                        </dl>
                    </Section>

                    {data.log.error && (
                        <Section title={m['admin.email.detail.error']()}>
                            <Code>{data.log.error}</Code>
                        </Section>
                    )}

                    {data.retry_history.length > 0 && (
                        <Section title={m['admin.email.detail.retryHistory']()}>
                            <ul className="flex flex-col gap-3">
                                {data.retry_history.map((a, i) => (
                                    <li key={i} className="flex items-start gap-3">
                                        <StatusChip status={a.status} />
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-[var(--color-ink)]">
                                                {m['admin.email.detail.attemptN']({ n: a.attempt })}
                                            </p>
                                            <p className="text-xs text-[var(--color-ink-faint)]">
                                                {fmt(a.timestamp)}
                                                {a.provider && ` · ${a.provider.toUpperCase()}`}
                                            </p>
                                            {a.error && (
                                                <p className="mt-1 rounded bg-[var(--color-danger)]/10 p-2 text-xs text-[var(--color-danger)]">
                                                    {a.error}
                                                </p>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    )}

                </div>
            )}
        </Modal>
    );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section>
            <h3 className="mb-3 text-sm font-semibold text-[var(--color-ink)]">{title}</h3>
            {children}
        </section>
    );
}

function Item({ label, children, mono }: { label: string; children: React.ReactNode; mono?: boolean }) {
    return (
        <div className="flex flex-col">
            <dt className="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-ink-faint)]">{label}</dt>
            <dd className={`text-sm text-[var(--color-ink)] ${mono ? 'break-all font-mono text-xs' : ''}`}>
                {children}
            </dd>
        </div>
    );
}

function Code({ children }: { children: React.ReactNode }) {
    return (
        <pre className="overflow-x-auto rounded-lg border border-[var(--color-border)] bg-[var(--color-canvas)] p-3 text-xs text-[var(--color-ink-muted)]">
            {children}
        </pre>
    );
}
