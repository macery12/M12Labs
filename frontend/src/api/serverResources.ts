import http from '@/lib/http';

export type PowerState = 'running' | 'starting' | 'stopping' | 'offline';
export type PowerSignal = 'start' | 'stop' | 'restart' | 'kill';

export interface ServerResources {
    state: PowerState;
    isSuspended: boolean;
    memoryBytes: number;
    cpuPercent: number;
    diskBytes: number;
    networkRxBytes: number;
    networkTxBytes: number;
    uptimeMs: number;
}

interface StatsAttributes {
    current_state: PowerState;
    is_suspended: boolean;
    resources: {
        memory_bytes: number;
        cpu_absolute: number;
        disk_bytes: number;
        network_rx_bytes: number;
        network_tx_bytes: number;
        uptime: number;
    };
}

// GET /api/client/servers/{id}/resources — live usage (20s server-side cache).
export async function getServerResources(id: string): Promise<ServerResources> {
    const { data } = await http.get(`/api/client/servers/${id}/resources`);
    return toResources(data.attributes);
}

/**
 * GET /api/client/servers/resources?ids= — live usage for a page of servers in
 * one request (one Wings call per node). A server that is suspended, installing
 * or on an unreachable node maps to null; one the caller cannot see is absent.
 */
export async function getServersResources(ids: string[]): Promise<Record<string, ServerResources | null>> {
    if (ids.length === 0) return {};

    const { data } = await http.get('/api/client/servers/resources', { params: { ids: ids.join(',') } });
    const entries = Object.entries((data.data ?? {}) as Record<string, StatsAttributes | null>);

    return Object.fromEntries(entries.map(([id, attributes]) => [id, attributes ? toResources(attributes) : null]));
}

function toResources(a: StatsAttributes): ServerResources {
    return {
        state: a.current_state,
        isSuspended: a.is_suspended,
        memoryBytes: a.resources.memory_bytes,
        cpuPercent: a.resources.cpu_absolute,
        diskBytes: a.resources.disk_bytes,
        networkRxBytes: a.resources.network_rx_bytes,
        networkTxBytes: a.resources.network_tx_bytes,
        uptimeMs: a.resources.uptime,
    };
}

// POST /api/client/servers/{id}/power
export async function sendPower(id: string, signal: PowerSignal): Promise<void> {
    await http.post(`/api/client/servers/${id}/power`, { signal });
}
