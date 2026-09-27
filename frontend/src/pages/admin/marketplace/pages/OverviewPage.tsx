import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Download, AlertTriangle, BarChart3, HardDrive, Clock, CheckCircle2 } from 'lucide-react';
import { Panel } from '@/components/ui/Panel';
import { Spinner } from '@/components/ui/Spinner';
import { m, td } from '@/i18n/messages';
import { formatBytes, timeAgo } from '@/lib/format';
import { getMarketplaceAnalytics, getMarketplaceFailures, type MarketplaceAnalytics } from '@/api/marketplaceAdmin';
import { formatCount } from '@/pages/server/marketplace/modMeta';
import { EmptyState, ErrorState } from '@/components/ui/EmptyState';

export default function OverviewPage() {
    const q = useQuery({
        queryKey: ['admin', 'marketplace', 'analytics'],
        queryFn: getMarketplaceAnalytics,
        refetchInterval: 30_000,
    });

    if (q.isLoading) {
        return (
            <div className="flex justify-center py-16">
                <Spinner className="h-6 w-6" />
            </div>
        );
    }
    if (q.isError || !q.data) {
        return <ErrorState error={q.error} onRetry={() => q.refetch()} retrying={q.isFetching} />;
    }

    const a = q.data;

    return (
        <div className="flex flex-col gap-5">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat icon={Download} label={m['admin.marketplace.overview.installs']()} value={formatCount(a.totals.installs)} />
                <Stat
                    icon={AlertTriangle}
                    label={m['admin.marketplace.overview.failures']()}
                    value={formatCount(a.totals.failures)}
                    // "14 failures" couldn't be followed anywhere; it now jumps to
                    // the list of what failed and why.
                    onClick={
                        a.totals.failures > 0
                            ? () => document.getElementById('recent-failures')?.scrollIntoView({ behavior: 'smooth' })
                            : undefined
                    }
                    actionLabel={m['admin.marketplace.overview.showFailures']()}
                />
                <Stat
                    icon={HardDrive}
                    label={m['admin.marketplace.overview.bandwidth24h']()}
                    value={formatBytes(a.totals.bandwidth_bytes_24h)}
                />
                <Stat
                    icon={Clock}
                    label={m['admin.marketplace.overview.queueActive']()}
                    value={String(a.queue.pending + a.queue.downloading)}
                />
            </div>

            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <Panel title={m['admin.marketplace.overview.byProvider']()}>
                    <div className="flex flex-col gap-2 p-1">
                        {(['modrinth', 'spigot', 'curseforge'] as const).map(p => (
                            <div key={p} className="flex items-center justify-between text-sm">
                                <span className="text-[var(--color-ink-muted)]">{td(`server.mods.provider.${p}`, p)}</span>
                                <span className="font-medium text-[var(--color-ink)]">
                                    {formatCount(a.totals.by_provider[p] ?? 0)}
                                </span>
                            </div>
                        ))}
                    </div>
                </Panel>

                <Panel title={m['admin.marketplace.overview.queue']()}>
                    <div className="flex flex-col gap-2 p-1">
                        <QueueRow label={m['admin.marketplace.overview.pending']()} value={a.queue.pending} />
                        <QueueRow label={m['admin.marketplace.overview.downloading']()} value={a.queue.downloading} />
                        <QueueRow label={m['admin.marketplace.overview.failed24h']()} value={a.queue.failed_24h} />
                    </div>
                </Panel>
            </div>

            <Panel title={m['admin.marketplace.overview.trend7d']()}>
                <div className="p-2">
                    {a.trends.last_7d.some(d => d.installs > 0) ? (
                        <TrendBars data={a.trends.last_7d.map(d => ({ label: d.date.slice(5), value: d.installs }))} />
                    ) : (
                        // A week of zero-height bars read as a broken chart.
                        <EmptyState
                            icon={BarChart3}
                            title={m['admin.marketplace.overview.trendEmpty']()}
                            body={m['admin.marketplace.overview.trendEmptyBody']()}
                        />
                    )}
                </div>
            </Panel>

            <RecentFailures />

            <Panel title={m['admin.marketplace.overview.providerHealth']()}>
                <div className="flex flex-col gap-3 p-1">
                    {Object.entries(a.provider_health).map(([key, health]) => (
                        <ProviderHealthRow key={key} name={td(`server.mods.provider.${key}`, key)} health={health} />
                    ))}
                </div>
            </Panel>
        </div>
    );
}

function Stat({
    icon: Icon,
    label,
    value,
    onClick,
    actionLabel,
}: {
    icon: typeof Download;
    label: string;
    value: string;
    onClick?: () => void;
    actionLabel?: string;
}) {
    const body = (
        <>
            <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-[var(--color-ink-faint)]">
                <Icon className="h-3.5 w-3.5" />
                {label}
            </div>
            <p className="mt-2 text-2xl font-semibold text-[var(--color-ink)]">{value}</p>
            {onClick && actionLabel && <p className="mt-1 text-xs font-medium text-[var(--brand-bright)]">{actionLabel} →</p>}
        </>
    );
    const cls = 'rounded-[var(--radius-card)] border border-[var(--color-border)] bg-[var(--color-surface)] p-4 text-left';

    return onClick ? (
        <button type="button" onClick={onClick} className={`${cls} transition-colors hover:border-[var(--brand)]/40`}>
            {body}
        </button>
    ) : (
        <div className={cls}>{body}</div>
    );
}

function RecentFailures() {
    const q = useQuery({ queryKey: ['admin', 'marketplace', 'failures'], queryFn: getMarketplaceFailures });

    return (
        <div id="recent-failures" className="scroll-mt-6">
            <Panel title={m['admin.marketplace.overview.recentFailures']()}>
                {q.isLoading ? (
                    <div className="flex justify-center py-6">
                        <Spinner className="h-5 w-5" />
                    </div>
                ) : q.isError ? (
                    <ErrorState error={q.error} onRetry={() => q.refetch()} retrying={q.isFetching} />
                ) : (q.data ?? []).length === 0 ? (
                    <EmptyState
                        icon={CheckCircle2}
                        title={m['admin.marketplace.overview.noFailures']()}
                        body={m['admin.marketplace.overview.noFailuresBody']()}
                    />
                ) : (
                    <ul className="flex flex-col divide-y divide-[var(--color-border)]">
                        {q.data!.map(f => (
                            <li key={f.id} className="flex flex-col gap-1 py-2.5 text-sm">
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <span className="font-medium text-[var(--color-ink)]">{f.file_name ?? f.project_id}</span>
                                    <span className="text-xs text-[var(--color-ink-faint)]">
                                        {td(`server.mods.provider.${f.provider}`, f.provider)} · {f.type}
                                    </span>
                                    {f.server && (
                                        <Link
                                            to={`/admin/infrastructure/servers/${f.server.id}`}
                                            className="text-xs text-[var(--brand-bright)] hover:underline"
                                        >
                                            {f.server.name}
                                        </Link>
                                    )}
                                    <span className="ml-auto text-xs text-[var(--color-ink-faint)]">{timeAgo(f.created_at)}</span>
                                </div>
                                <p className={`text-xs ${f.error ? 'text-[var(--color-danger)]' : 'text-[var(--color-ink-faint)]'}`}>
                                    {f.error ?? m['admin.marketplace.overview.noReason']()}
                                </p>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </div>
    );
}

function QueueRow({ label, value }: { label: string; value: number }) {
    return (
        <div className="flex items-center justify-between text-sm">
            <span className="text-[var(--color-ink-muted)]">{label}</span>
            <span className="font-medium text-[var(--color-ink)]">{value}</span>
        </div>
    );
}

function TrendBars({ data }: { data: Array<{ label: string; value: number }> }) {
    const max = Math.max(1, ...data.map(d => d.value));
    return (
        <div className="flex h-40 items-end gap-2">
            {data.map((d, i) => (
                <div key={i} className="flex flex-1 flex-col items-center gap-1">
                    <div className="flex w-full flex-1 items-end">
                        <div
                            className="w-full rounded-t bg-[var(--brand)]/70"
                            style={{ height: `${(d.value / max) * 100}%` }}
                            title={String(d.value)}
                        />
                    </div>
                    <span className="text-[10px] text-[var(--color-ink-faint)]">{d.label}</span>
                </div>
            ))}
        </div>
    );
}

function ProviderHealthRow({
    name,
    health,
}: {
    name: string;
    health: MarketplaceAnalytics['provider_health'][string];
}) {
    return (
        <div className="flex items-center justify-between gap-3 text-sm">
            <div className="flex items-center gap-2">
                <span
                    className={`h-2 w-2 rounded-full ${
                        health.enabled ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-ink-faint)]'
                    }`}
                />
                <span className="text-[var(--color-ink)]">{name}</span>
            </div>
            <div className="flex items-center gap-4 text-xs text-[var(--color-ink-muted)]">
                {health.rate_limit && (
                    <span>
                        {m['admin.marketplace.overview.rateLimit']({
                            used: health.rate_limit.requests_this_minute,
                            limit: health.rate_limit.limit_per_minute,
                        })}
                    </span>
                )}
                {health.denied_by_policy > 0 && (
                    <span className="text-[var(--color-warning)]">
                        {m['admin.marketplace.overview.denied']({ count: health.denied_by_policy })}
                    </span>
                )}
            </div>
        </div>
    );
}
