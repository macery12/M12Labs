import { m, td } from '@/i18n/messages';
import { useQueries } from '@tanstack/react-query';
import { getNodeServerStates, type ServerState } from '@/api/adminServers';
import { Badge } from '@/pages/admin/nodes/NodeBadges';
import { POWER_STATE, SERVER_STATE, type PowerView } from './serverState';

// How often a visible list re-asks its nodes. The server caches each node's
// answer for ~20 s, so polling faster than that only re-reads the cache.
const POLL_MS = 30_000;

/**
 * Power state for servers across any number of nodes, fetched once per node.
 * Returns a lookup so a table can resolve each row without its own query.
 */
export function usePowerStates(nodeIds: number[]): (server: { uuid: string; nodeId: number }) => PowerView {
    const nodes = [...new Set(nodeIds)].sort((a, b) => a - b);
    const results = useQueries({
        queries: nodes.map(id => ({
            queryKey: ['admin', 'node-server-states', id],
            queryFn: () => getNodeServerStates(id),
            refetchInterval: POLL_MS,
            staleTime: POLL_MS / 2,
            retry: false,
        })),
    });

    return server => {
        const result = results[nodes.indexOf(server.nodeId)];
        if (!result || result.isPending) return 'checking';
        if (result.isError || !result.data.reachable) return 'unknown';
        // A reachable node that doesn't list the server hasn't created it yet
        // (or lost it); either way the panel can't say it's running.
        return result.data.states[server.uuid] ?? 'unknown';
    };
}

function powerLabel(power: PowerView): string {
    switch (power) {
        case 'running':
            return m['admin.servers.power.running']();
        case 'starting':
            return m['common.states.starting']();
        case 'stopping':
            return m['common.states.stopping']();
        case 'offline':
            return m['common.states.offline']();
        case 'checking':
            return m['ui.states.checking']();
        default:
            return m['ui.states.unknown']();
    }
}

function LifecycleBadge({ lifecycle }: { lifecycle: ServerState }) {
    // "Active" is the absence of a lifecycle event, so it earns no badge.
    if (lifecycle === 'active') return null;
    const s = SERVER_STATE[lifecycle];
    return <Badge tone={s.tone}>{td(`admin.servers.state.${lifecycle}`, s.label)}</Badge>;
}

/**
 * Dot + label: power state, then any lifecycle badge. `md` matches body text
 * for fact rows; the default suits table cells.
 */
export function ServerStatusDot({ power, lifecycle, size = 'sm' }: { power: PowerView; lifecycle: ServerState; size?: 'sm' | 'md' }) {
    return (
        <span
            className="flex flex-wrap items-center gap-2"
            title={power === 'unknown' ? m['admin.servers.power.unknownHint']() : undefined}
        >
            <span className="flex items-center gap-2">
                <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: POWER_STATE[power].color }} />
                <span className={size === 'md' ? 'text-sm text-[var(--color-ink)]' : 'text-[11px] font-medium text-[var(--color-ink-muted)]'}>
                    {powerLabel(power)}
                </span>
            </span>
            <LifecycleBadge lifecycle={lifecycle} />
        </span>
    );
}

/** Badge pair for a page header. */
export function ServerStatusBadges({ power, lifecycle }: { power: PowerView; lifecycle: ServerState }) {
    return (
        <>
            <Badge tone={POWER_STATE[power].tone}>
                <span title={power === 'unknown' ? m['admin.servers.power.unknownHint']() : undefined}>{powerLabel(power)}</span>
            </Badge>
            <LifecycleBadge lifecycle={lifecycle} />
        </>
    );
}
