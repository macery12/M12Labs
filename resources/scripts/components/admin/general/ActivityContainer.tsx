import AdminContentBlock from '@/elements/AdminContentBlock';
import AlertRenderer from '@/components/AlertRenderer';
import { useEffect, useState } from 'react';
import { useFlashKey } from '@/plugins/useFlash';
import { Link } from 'react-router-dom';
import PaginationFooter from '@/elements/table/PaginationFooter';
import { DesktopComputerIcon, XCircleIcon } from '@heroicons/react/solid';
import Spinner from '@/elements/Spinner';
import { styles as btnStyles } from '@/elements/button/index';
import classNames from 'classnames';
import ActivityLogEntry from '@/elements/activity/ActivityLogEntry';
import Tooltip from '@/elements/tooltip/Tooltip';
import useLocationHash from '@/plugins/useLocationHash';
import { ActivityLogFilters, useActivityLogs } from '@/api/routes/admin/activity';
import { useStoreState } from '@/state/hooks';

export default () => {
    const { hash } = useLocationHash();
    const { clearAndAddHttpError } = useFlashKey('account');
    const [filters, setFilters] = useState<ActivityLogFilters>({ page: 1, sorts: { timestamp: -1 } });
    const { data, isValidating, error } = useActivityLogs(filters, {
        revalidateOnMount: true,
        revalidateOnFocus: false,
    });

    const enabled: boolean = useStoreState(state => state.settings.data!.activity.enabled.admin);

    if (!enabled) return <></>;

    useEffect(() => {
        setFilters(value => ({ ...value, filters: { ip: hash.ip, event: hash.event } }));
    }, [hash]);

    useEffect(() => {
        clearAndAddHttpError(error);
    }, [error]);

    return (
        <AdminContentBlock title={'Admin Activity'}>
            <AlertRenderer filterByKey={'admin:activity'} className={'mb-4'} position="top-center" />
            <div className={'mb-8 flex w-full flex-row items-center'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Admin Activity</h2>
                    <p
                        className={
                            'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                        }
                    >
                        Get detailed insights into what actions administrators are performing.
                    </p>
                </div>
            </div>
            {(filters.filters?.event || filters.filters?.ip) && (
                <div className={'mb-2 flex justify-end'}>
                    <Link
                        to={'#'}
                        className={classNames(btnStyles.button, btnStyles.text, 'w-full sm:w-auto')}
                        onClick={() => setFilters(value => ({ ...value, filters: {} }))}
                    >
                        Clear Filters <XCircleIcon className={'ml-2 h-4 w-4'} />
                    </Link>
                </div>
            )}
            {isValidating ? (
                <Spinner centered />
            ) : (
                <div className={'bg-slate-700'}>
                    {data ? (
                        <>
                            {data?.items.map(activity => (
                                <ActivityLogEntry key={activity.id} activity={activity}>
                                    {typeof activity.properties.useragent === 'string' && (
                                        <Tooltip content={activity.properties.useragent} placement={'top'}>
                                            <span>
                                                <DesktopComputerIcon />
                                            </span>
                                        </Tooltip>
                                    )}
                                </ActivityLogEntry>
                            ))}
                        </>
                    ) : (
                        <p className={'text-center text-white'}>There are no admin logs available at this time.</p>
                    )}
                </div>
            )}
            {data && (
                <PaginationFooter
                    pagination={data.pagination}
                    onPageSelect={page => setFilters(value => ({ ...value, page }))}
                />
            )}
        </AdminContentBlock>
    );
};
