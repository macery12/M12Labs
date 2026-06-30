import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Cpu, MemoryStick, HardDrive, Server, Info, Activity, AlertTriangle, Zap } from 'lucide-react';
import { Panel } from '@/components/ui/Panel';
import { Meter } from '@/components/ui/Meter';
import { useNode } from '../NodeContext';
import { getNodeInformation, getNodeUtilization } from '@/api/nodes';
import { getWingsRsOverview } from '@/api/wingsRs';
import { formatBytes, formatMib, formatUptime } from '@/lib/format';

function Row({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-4 border-b border-[var(--color-border)] py-2 last:border-0">
            <span className="text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--color-ink-faint)]">{label}</span>
            <span className="truncate font-mono text-xs tabular-nums text-[var(--color-ink)]">{value}</span>
        </div>
    );
}

// Wings-RS is the primary thing to look at when it's present, so its overview
// leads the right rail. Sourced from /wings-rs/overview.
function WingsRsOverviewPanel() {
    const { t } = useTranslation(['admin', 'common']);
    const node = useNode();
    const { data: overview, isError } = useQuery({
        queryKey: ['admin', 'wingsrs-overview', node.id],
        queryFn: () => getWingsRsOverview(node.id),
        retry: false,
        staleTime: 30_000,
    });

    return (
        <Panel
            title={t('nodes.wingsRs.title')}
            icon={Zap}
            right={
                <span className="font-mono text-[10px] uppercase tracking-wider text-[var(--color-accent)]">{t('nodes.wingsRs.supercharged')}</span>
            }
            className="border-[var(--color-accent)]/40"
        >
            {isError ? (
                <p className="py-4 text-sm text-[var(--color-ink-faint)]">{t('nodes.wingsRs.overviewUnreachable')}</p>
            ) : !overview ? (
                <p className="py-4 text-sm text-[var(--color-ink-faint)]">{t('common:states.loading')}</p>
            ) : (
                <div className="flex flex-col">
                    <Row label={t('nodes.wingsRs.version')} value={overview.version} />
                    <Row label={t('nodes.wingsRs.rust')} value={overview.rustVersion ?? '—'} />
                    <Row label={t('nodes.wingsRs.build')} value={overview.buildDate ?? '—'} />
                    <Row label={t('nodes.wingsRs.kernel')} value={overview.kernel} />
                    <Row label={t('nodes.wingsRs.uptime')} value={overview.uptime != null ? formatUptime(overview.uptime * 1000) : '—'} />
                    <Row label={t('nodes.wingsRs.features')} value={overview.features.length > 0 ? t('nodes.wingsRs.featuresEnabled', { count: overview.features.length }) : '—'} />
                </div>
            )}
        </Panel>
    );
}

export function NodeOverviewTab() {
    const { t } = useTranslation(['admin', 'common']);
    const node = useNode();
    const supercharged = node.wingsType === 'wings-rs';

    // Live host utilization — auto-polls the daemon every ~2.5s while mounted.
    const { data: util, isError: utilError, isLoading: utilLoading } = useQuery({
        queryKey: ['admin', 'node-utilization', node.id],
        queryFn: () => getNodeUtilization(node.id),
        refetchInterval: 2500,
        refetchOnWindowFocus: true,
        retry: false,
    });

    // Host system information — from /information (OS, arch, kernel, CPU count, daemon version).
    const { data: info } = useQuery({
        queryKey: ['admin', 'node-information', node.id],
        queryFn: () => getNodeInformation(node.id),
        retry: false,
        staleTime: 60_000,
    });

    const pct = (used: number, total: number) => (total > 0 ? (used / total) * 100 : null);

    return (
        <div className="grid gap-4 lg:grid-cols-3">
            <div className="lg:col-span-2">
                <Panel
                    title={t('nodes.overview.liveHost')}
                    icon={Activity}
                    right={
                        <span className="flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-wider text-[var(--color-ink-faint)]">
                            <span
                                className={`h-1.5 w-1.5 rounded-full ${utilError ? 'bg-[var(--color-danger)]' : 'bg-[var(--color-accent)]'}`}
                            />
                            {utilError ? t('nodes.overview.unreachable') : t('nodes.overview.live')}
                        </span>
                    }
                >
                    {utilError ? (
                        <div className="flex items-center gap-2 py-6 text-sm text-[var(--color-ink-faint)]">
                            <AlertTriangle className="h-4 w-4 text-[var(--color-danger)]" />
                            {t('nodes.overview.daemonUnreachable')}
                        </div>
                    ) : utilLoading || !util ? (
                        <div className="py-6 text-sm text-[var(--color-ink-faint)]">{t('nodes.overview.connectingDaemon')}</div>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Meter icon={Cpu} label={t('common:metrics.cpu')} value={`${util.cpu.toFixed(1)}%`} percent={util.cpu} />
                            <Meter
                                icon={MemoryStick}
                                label={t('common:metrics.memory')}
                                value={`${formatBytes(util.memory.used)} / ${formatBytes(util.memory.total)}`}
                                percent={pct(util.memory.used, util.memory.total)}
                            />
                            <Meter
                                icon={HardDrive}
                                label={t('common:metrics.disk')}
                                value={`${formatBytes(util.disk.used)} / ${formatBytes(util.disk.total)}`}
                                percent={pct(util.disk.used, util.disk.total)}
                            />
                            <Meter
                                icon={Activity}
                                label={t('nodes.overview.swap')}
                                value={util.swap.total > 0 ? `${formatBytes(util.swap.used)} / ${formatBytes(util.swap.total)}` : t('nodes.overview.swapDisabled')}
                                percent={pct(util.swap.used, util.swap.total)}
                            />
                        </div>
                    )}
                </Panel>
            </div>

            <div className="flex flex-col gap-4">
                {/* When Wings-RS is present it's the primary thing to look at, so it leads. */}
                {supercharged && <WingsRsOverviewPanel />}

                <Panel title={t('nodes.overview.allocatedCapacity')} icon={Server}>
                    <div className="flex flex-col gap-4">
                        <Meter
                            icon={MemoryStick}
                            label={t('common:metrics.memory')}
                            value={`${formatMib(node.allocatedMemory)} / ${node.memory > 0 ? formatMib(node.memory) : '∞'}`}
                            percent={node.utilization.memory}
                        />
                        <Meter
                            icon={HardDrive}
                            label={t('common:metrics.disk')}
                            value={`${formatMib(node.allocatedDisk)} / ${node.disk > 0 ? formatMib(node.disk) : '∞'}`}
                            percent={node.utilization.disk}
                        />
                        <Meter icon={Server} label={t('nodes.overview.allocations')} value={`${node.utilization.allocations}%`} percent={node.utilization.allocations} />
                    </div>
                </Panel>

                <Panel title={t('nodes.overview.systemInfo')} icon={Info}>
                    <div className="flex flex-col">
                        <Row label={t('nodes.overview.daemon')} value={info?.version ?? '—'} />
                        <Row label={t('nodes.overview.os')} value={info?.system.type ?? '—'} />
                        <Row label={t('nodes.overview.arch')} value={info?.system.arch ?? '—'} />
                        <Row label={t('nodes.overview.kernel')} value={info?.system.release ?? '—'} />
                        <Row label={t('nodes.overview.cpuThreads')} value={info?.system.cpus ?? '—'} />
                        <Row label={t('nodes.overview.overallocate')} value={t('nodes.overview.overallocateValue', { memory: node.memoryOverallocate, disk: node.diskOverallocate })} />
                    </div>
                </Panel>
            </div>
        </div>
    );
}
