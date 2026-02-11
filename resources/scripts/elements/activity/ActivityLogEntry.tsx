import * as React from 'react';
import { Link } from 'react-router-dom';
import Tooltip from '@/elements/tooltip/Tooltip';
import Translate from '@/elements/Translate';
import { format, formatDistanceToNowStrict } from 'date-fns';
import { ActivityLog } from '@definitions/account';
import ActivityLogMetaButton from '@/elements/activity/ActivityLogMetaButton';
import FileDiffViewer, { FileDiff } from '@/elements/activity/FileDiffViewer';
import { FolderOpenIcon, TerminalIcon } from '@heroicons/react/solid';
import classNames from 'classnames';
import style from './style.module.css';
import Avatar from '@/elements/Avatar';
import useLocationHash from '@/plugins/useLocationHash';
import { getObjectKeys, isObject } from '@/lib/objects';
import { useStoreState } from '@/state/hooks';

interface Props {
    activity: ActivityLog;
    children?: React.ReactNode;
}

function wrapProperties(value: unknown): any {
    if (value === null || typeof value === 'string' || typeof value === 'number') {
        return `<strong>${String(value)}</strong>`;
    }

    if (isObject(value)) {
        return getObjectKeys(value).reduce((obj, key) => {
            if (key === 'count' || (typeof key === 'string' && key.endsWith('_count'))) {
                return { ...obj, [key]: value[key] };
            }
            return { ...obj, [key]: wrapProperties(value[key]) };
        }, {} as Record<string, unknown>);
    }

    if (Array.isArray(value)) {
        return value.map(wrapProperties);
    }

    return value;
}

function hasFileDiff(activity: ActivityLog): boolean {
    return (
        activity.event === 'server:file.write' &&
        activity.properties?.diff !== undefined &&
        typeof activity.properties.diff === 'object'
    );
}

function getFileDiff(activity: ActivityLog): FileDiff | null {
    if (!hasFileDiff(activity)) return null;

    const diff = activity.properties.diff as Record<string, unknown>;
    return {
        file: activity.properties.file as string | undefined,
        additions: (diff.additions as number) || 0,
        deletions: (diff.deletions as number) || 0,
        hunks: (diff.hunks as FileDiff['hunks']) || [],
        is_new_file: (diff.is_new_file as boolean) || false,
        large_file: (diff.large_file as boolean) || false,
    };
}

export default ({ activity, children }: Props) => {
    const { pathTo } = useLocationHash();
    const actor = activity.relationships.actor;
    const properties = wrapProperties(activity.properties);
    const { colors } = useStoreState(state => state.theme.data!);
    const fileDiff = getFileDiff(activity);

    return (
        <div
            className={'group grid grid-cols-10 py-5 px-4 last:rounded-b last:border-0 border-b border-slate-600/50 hover:bg-slate-600/30 transition-colors duration-150'}
            style={{ backgroundColor: colors.secondary }}
        >
            {/* Avatar Column - Always visible on larger screens */}
            <div className={'hidden items-start justify-center pt-1 sm:col-span-1 sm:flex 2xl:col-span-1'}>
                <div className={'flex h-12 w-12 items-center overflow-hidden rounded-full bg-slate-600 ring-2 ring-slate-500/50'}>
                    <Avatar name={actor?.uuid || 'system'} />
                </div>
            </div>

            {/* Content Column */}
            <div className={'col-span-10 flex flex-col sm:col-span-9'}>
                <div className={'flex flex-col space-y-2'}>
                    {/* Main Info Row */}
                    <div className={'flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2'}>
                        <div className={'flex-1 min-w-0'}>
                            {/* User and Action */}
                            <div className={'flex flex-wrap items-center gap-x-2 gap-y-1'}>
                                <div className={'flex items-center gap-2'}>
                                    {/* Mobile Avatar */}
                                    <div className={'flex h-8 w-8 items-center overflow-hidden rounded-full bg-slate-600 sm:hidden'}>
                                        <Avatar name={actor?.uuid || 'system'} />
                                    </div>
                                    <Tooltip placement={'top'} content={actor?.email || 'System User'}>
                                        <span className={'font-semibold text-slate-50 text-base'}>
                                            {actor?.username || 'System'}
                                        </span>
                                    </Tooltip>
                                </div>
                                <div className={classNames(style.icons, 'group-hover:text-slate-300')}>
                                    {activity.isApi && (
                                        <Tooltip placement={'top'} content={'Using API Key'}>
                                            <TerminalIcon />
                                        </Tooltip>
                                    )}
                                    {activity.event.startsWith('server:sftp.') && (
                                        <Tooltip placement={'top'} content={'Using SFTP'}>
                                            <FolderOpenIcon />
                                        </Tooltip>
                                    )}
                                    {children}
                                </div>
                            </div>

                            {/* Action Description */}
                            <div className={'mt-1.5'}>
                                <Link
                                    to={`#${pathTo({ event: activity.event })}`}
                                    className={
                                        'font-medium text-slate-200 transition-colors duration-75 hover:text-cyan-400 active:text-cyan-400'
                                    }
                                >
                                    {activity.description ?? activity.event}
                                </Link>
                            </div>

                            {/* Activity Details */}
                            <p className={classNames(style.description, 'mt-1')}>
                                <Translate ns={'activity'} values={properties} i18nKey={activity.event.replace(':', '.')} />
                            </p>

                            {fileDiff && (
                                <div className="mt-2 text-xs text-slate-400">
                                    <span className="text-green-400">+{fileDiff.additions}</span>
                                    <span className="mx-1">/</span>
                                    <span className="text-red-400">-{fileDiff.deletions}</span>
                                    <span className="ml-1">lines changed</span>
                                </div>
                            )}
                        </div>

                        {/* Timestamp - Right aligned on desktop */}
                        <div className={'flex-shrink-0'}>
                            <Tooltip placement={'left'} content={format(activity.timestamp, 'MMM do, yyyy H:mm:ss')}>
                                <span className={'text-sm text-slate-400'}>
                                    {formatDistanceToNowStrict(activity.timestamp, { addSuffix: true })}
                                </span>
                            </Tooltip>
                        </div>
                    </div>

                    {/* Secondary Info Row */}
                    <div className={'flex items-center justify-between text-xs text-slate-500'}>
                        <div className={'flex items-center gap-2'}>
                            {activity.ip && (
                                <span className={'font-mono bg-slate-700/50 px-2 py-0.5 rounded'}>
                                    {activity.ip}
                                </span>
                            )}
                            {activity.id && (
                                <span className={'text-slate-600'}>
                                    ID: {activity.id.substring(0, 8)}
                                </span>
                            )}
                        </div>
                        {activity.hasAdditionalMetadata && <ActivityLogMetaButton meta={activity.properties} />}
                    </div>
                </div>

                {/* File Diff */}
                {fileDiff && (
                    <div className="mt-3">
                        <FileDiffViewer diff={fileDiff} />
                    </div>
                )}
            </div>
        </div>
    );
};
