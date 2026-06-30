import { Server, Activity, MemoryStick, HardDrive } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatMib } from '@/lib/format';
import type { ServerListItem } from '@/api/servers';

function Tile({
    icon: Icon,
    label,
    value,
    sub,
}: {
    icon: typeof Server;
    label: string;
    value: string;
    sub?: string;
}) {
    return (
        <div className="flex items-center gap-4 rounded-2xl border border-[var(--color-border-strong)] bg-[var(--color-surface)]/70 p-5">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--color-surface-2)]">
                <Icon className="h-5 w-5 text-[var(--brand)]" />
            </div>
            <div className="min-w-0">
                <p className="text-xs uppercase tracking-wide text-[var(--color-ink-faint)]">{label}</p>
                <p className="text-xl font-semibold text-[var(--color-ink)]">
                    {value}
                    {sub && <span className="ml-1 text-sm font-normal text-[var(--color-ink-muted)]">{sub}</span>}
                </p>
            </div>
        </div>
    );
}

export function StatTiles({ servers, running }: { servers: ServerListItem[]; running: number | null }) {
    const { t } = useTranslation('dashboard');
    const totalMem = servers.reduce((sum, s) => sum + s.limits.memory, 0);
    const totalDisk = servers.reduce((sum, s) => sum + s.limits.disk, 0);

    return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Tile icon={Server} label={t('stats.servers')} value={String(servers.length)} />
            <Tile
                icon={Activity}
                label={t('stats.running')}
                value={running === null ? '—' : String(running)}
                sub={running === null ? '' : `/ ${servers.length}`}
            />
            <Tile icon={MemoryStick} label={t('stats.memory')} value={totalMem === 0 ? '∞' : formatMib(totalMem)} sub={t('stats.allocated')} />
            <Tile icon={HardDrive} label={t('stats.storage')} value={totalDisk === 0 ? '∞' : formatMib(totalDisk)} sub={t('stats.allocated')} />
        </div>
    );
}
