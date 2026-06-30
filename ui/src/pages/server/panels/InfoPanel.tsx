import { Cpu } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Panel } from './Panel';
import { useServer } from '@/components/server/ServerContext';
import { formatMib } from '@/lib/format';

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-3 border-b border-[var(--color-border)] py-2 last:border-0">
            <span className="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-faint)]">
                {label}
            </span>
            <span className="min-w-0 truncate font-mono text-xs tabular-nums text-[var(--color-ink)]">{value}</span>
        </div>
    );
}

export function InfoPanel() {
    const { t } = useTranslation(['server', 'common']);
    const server = useServer();
    const fl = server.featureLimits;

    return (
        <Panel title={t('info.title')} icon={Cpu}>
            <div className="flex flex-col">
                <Row label={t('info.node')} value={server.node} />
                <Row label={t('info.image')} value={server.dockerImage.split('/').pop() || server.dockerImage || '—'} />
                <Row label={t('common:metrics.cpu')} value={server.limits.cpu > 0 ? `${server.limits.cpu}%` : t('info.unlimited')} />
                <Row label={t('common:metrics.memory')} value={formatMib(server.limits.memory)} />
                <Row label={t('common:metrics.disk')} value={formatMib(server.limits.disk)} />
                <Row label={t('info.databases')} value={String(fl.databases)} />
                <Row label={t('info.backups')} value={String(fl.backups)} />
                <Row label={t('info.uuid')} value={server.uuid.split('-')[0] ?? server.uuid} />
            </div>
        </Panel>
    );
}
