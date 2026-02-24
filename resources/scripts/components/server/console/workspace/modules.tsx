import { useEffect, useMemo, useRef } from 'react';
import { Line } from 'react-chartjs-2';
import { CloudDownloadIcon, CloudUploadIcon } from '@heroicons/react/solid';
import classNames from 'classnames';
import { theme } from 'twin.macro';
import StatBlock from '@server/console/StatBlock';
import UptimeDuration from '@server/UptimeDuration';
import ChartBlock from '@server/console/ChartBlock';
import { useChart, useChartTickLabel } from '@server/console/chart';
import { hexToRgba } from '@/lib/helpers';
import { bytesToString } from '@/lib/formatters';
import { capitalize } from '@/lib/strings';
import Tooltip from '@/elements/tooltip/Tooltip';
import { useStoreState } from '@/state/hooks';
import { useConsoleStats } from '../ConsoleStatsProvider';
import styles from '@server/console/style.module.css';

function getBackgroundColor(value: number, max: number | null): string | undefined {
    const delta = !max ? 0 : value / max;

    if (delta > 0.8) {
        if (delta > 0.9) {
            return '#ef4444';
        }
        return '#f59e0b';
    }

    return undefined;
}

export const AddressModule = () => {
    const { allocation } = useConsoleStats();

    return (
        <StatBlock icon={undefined} title={'Address'} copyOnClick={allocation} className={classNames(styles.stat_block, '!px-4')}>
            {allocation}
        </StatBlock>
    );
};

export const UptimeModule = () => {
    const { stats, status } = useConsoleStats();

    return (
        <StatBlock
            icon={undefined}
            title={'Uptime'}
            className={classNames(styles.stat_block, '!px-4')}
            color={getBackgroundColor(status === 'running' ? 0 : status !== 'offline' ? 9 : 10, 10)}
        >
            {status === null ? 'Offline' : stats.uptime > 0 ? <UptimeDuration uptime={stats.uptime / 1000} /> : capitalize(status)}
        </StatBlock>
    );
};

export const CpuSummaryModule = () => {
    const { stats, limits, textLimits, status } = useConsoleStats();

    return (
        <StatBlock
            icon={undefined}
            title={'CPU Load'}
            className={classNames(styles.stat_block, '!px-4')}
            color={getBackgroundColor(stats.cpu, limits.cpu ?? null)}
        >
            {status === 'offline' ? <span className={'text-slate-400'}>Offline</span> : <>{stats.cpu.toFixed(2)}%{textLimits.cpu ? <span className={'ml-1 select-none text-[70%] text-slate-300'}>/ {textLimits.cpu}</span> : null}</>}
        </StatBlock>
    );
};

export const MemorySummaryModule = () => {
    const { stats, limits, textLimits, status } = useConsoleStats();

    return (
        <StatBlock
            icon={undefined}
            title={'Memory'}
            className={classNames(styles.stat_block, '!px-4')}
            color={getBackgroundColor(stats.memory / 1024, (limits.memory || 0) * 1024)}
        >
            {status === 'offline' ? (
                <span className={'text-slate-400'}>Offline</span>
            ) : (
                <>
                    {bytesToString(stats.memory)}
                    {textLimits.memory && <span className={'ml-1 select-none text-[70%] text-slate-300'}>/ {textLimits.memory}</span>}
                </>
            )}
        </StatBlock>
    );
};

export const DiskSummaryModule = () => {
    const { stats, limits, textLimits } = useConsoleStats();

    return (
        <StatBlock icon={undefined} title={'Disk'} className={classNames(styles.stat_block, '!px-4')}>
            <>
                {bytesToString(stats.disk)}
                {textLimits.disk && <span className={'ml-1 select-none text-[70%] text-slate-300'}>/ {textLimits.disk}</span>}
            </>
        </StatBlock>
    );
};

export const CpuGraphModule = () => {
    const { limits, status, stats } = useConsoleStats();
    const cpu = useChartTickLabel('CPU', limits.cpu, '%', 0);

    useEffect(() => {
        if (status === 'offline') {
            cpu.clear();
        }
    }, [status]);

    useEffect(() => {
        cpu.push(stats.cpu);
    }, [stats.cpu]);

    return (
        <ChartBlock title={'CPU'}>
            <Line {...cpu.props} />
        </ChartBlock>
    );
};

export const MemoryGraphModule = () => {
    const { limits, status, stats } = useConsoleStats();
    const memory = useChartTickLabel('Memory', limits.memory, 'MiB');

    useEffect(() => {
        if (status === 'offline') {
            memory.clear();
        }
    }, [status]);

    useEffect(() => {
        memory.push(Math.floor(stats.memory / 1024 / 1024));
    }, [stats.memory]);

    return (
        <ChartBlock title={'Memory'}>
            <Line {...memory.props} />
        </ChartBlock>
    );
};

export const NetworkGraphModule = () => {
    const { primary } = useStoreState(state => state.theme.data!.colors);
    const { status, stats } = useConsoleStats();
    const previous = useRef<Record<'tx' | 'rx', number>>({ tx: -1, rx: -1 });

    const network = useChart('Network', {
        sets: 2,
        options: {
            scales: {
                y: {
                    ticks: {
                        callback(value) {
                            return bytesToString(typeof value === 'string' ? parseInt(value, 10) : value);
                        },
                    },
                },
            },
        },
        callback(opts, index) {
            return {
                ...opts,
                label: !index ? 'Network In' : 'Network Out',
                borderColor: !index ? theme('colors.cyan.400') : primary,
                backgroundColor: hexToRgba(!index ? theme('colors.cyan.700') : primary, 0.5),
            };
        },
    });

    useEffect(() => {
        if (status === 'offline') {
            network.clear();
            previous.current = { tx: -1, rx: -1 };
        }
    }, [status]);

    useEffect(() => {
        const nextIn = previous.current.rx < 0 ? 0 : Math.max(0, stats.rx - previous.current.rx);
        const nextOut = previous.current.tx < 0 ? 0 : Math.max(0, stats.tx - previous.current.tx);

        network.push([nextIn, nextOut]);
        previous.current = { tx: stats.tx, rx: stats.rx };
    }, [stats.tx, stats.rx]);

    return (
        <ChartBlock
            title={'Network'}
            legend={
                <>
                    <Tooltip arrow content={'Inbound'}>
                        <CloudDownloadIcon className={'mr-2 h-4 w-4 text-green-400'} />
                    </Tooltip>
                    <Tooltip arrow content={'Outbound'}>
                        <CloudUploadIcon className={'h-4 w-4 text-cyan-400'} />
                    </Tooltip>
                </>
            }
        >
            <Line {...network.props} />
        </ChartBlock>
    );
};
