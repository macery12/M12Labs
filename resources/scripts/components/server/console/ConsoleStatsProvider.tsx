import { createContext, type ReactNode, useContext, useEffect, useMemo, useState } from 'react';
import { SocketEvent, SocketRequest } from '@server/events';
import useWebsocketEvent from '@/plugins/useWebsocketEvent';
import { bytesToString, ip, mbToBytes } from '@/lib/formatters';
import { ServerContext, type ServerStatus } from '@/state/server';

type Stats = Record<'memory' | 'cpu' | 'disk' | 'uptime' | 'rx' | 'tx', number>;

interface ConsoleStatsContext {
    stats: Stats;
    status: ServerStatus;
    allocation: string;
    limits: {
        cpu?: number | null;
        memory?: number | null;
        disk?: number | null;
    };
    textLimits: {
        cpu: string | null;
        memory: string | null;
        disk: string | null;
    };
}

const Context = createContext<ConsoleStatsContext | null>(null);

export const ConsoleStatsProvider = ({ children }: { children: ReactNode }) => {
    const [stats, setStats] = useState<Stats>({ memory: 0, cpu: 0, disk: 0, uptime: 0, tx: 0, rx: 0 });

    const status = ServerContext.useStoreState(state => state.status.value);
    const connected = ServerContext.useStoreState(state => state.socket.connected);
    const instance = ServerContext.useStoreState(state => state.socket.instance);
    const limits = ServerContext.useStoreState(state => state.server.data!.limits);

    const textLimits = useMemo(
        () => ({
            cpu: limits?.cpu ? `${limits.cpu}%` : null,
            memory: limits?.memory ? bytesToString(mbToBytes(limits.memory)) : null,
            disk: limits?.disk ? bytesToString(mbToBytes(limits.disk)) : null,
        }),
        [limits],
    );

    const allocation = ServerContext.useStoreState(state => {
        const match = state.server.data!.allocations.find(allocation => allocation.isDefault);

        return !match ? 'n/a' : `${match.alias || ip(match.ip)}:${match.port}`;
    });

    useEffect(() => {
        if (!connected || !instance) {
            return;
        }

        instance.send(SocketRequest.SEND_STATS);
    }, [instance, connected]);

    useWebsocketEvent(SocketEvent.STATS, data => {
        let payload: any = {};
        try {
            payload = JSON.parse(data);
        } catch (e) {
            return;
        }

        setStats({
            memory: payload.memory_bytes,
            cpu: payload.cpu_absolute,
            disk: payload.disk_bytes,
            tx: payload.network.tx_bytes,
            rx: payload.network.rx_bytes,
            uptime: payload.uptime || 0,
        });
    });

    return (
        <Context.Provider value={{ stats, status, allocation, limits, textLimits }}>
            {children}
        </Context.Provider>
    );
};

export const useConsoleStats = (): ConsoleStatsContext => {
    const ctx = useContext(Context);

    if (!ctx) {
        throw new Error('useConsoleStats must be used within ConsoleStatsProvider');
    }

    return ctx;
};
