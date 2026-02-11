import AdminContentBlock from '@/elements/AdminContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { useEffect, useMemo, useState } from 'react';
import { useFlashKey } from '@/plugins/useFlash';
import { Link } from 'react-router-dom';
import PaginationFooter from '@/elements/table/PaginationFooter';
import { DesktopComputerIcon, XCircleIcon, SearchIcon } from '@heroicons/react/solid';
import Spinner from '@/elements/Spinner';
import { styles as btnStyles } from '@/elements/button/index';
import classNames from 'classnames';
import ActivityLogEntry from '@/elements/activity/ActivityLogEntry';
import Tooltip from '@/elements/tooltip/Tooltip';
import useLocationHash from '@/plugins/useLocationHash';
import { ActivityLogFilters, useActivityLogs } from '@/api/routes/admin/activity';
import { useStoreState } from '@/state/hooks';
import Input from '@/elements/Input';
import Select from '@/elements/Select';
import debounce from 'debounce';
import { ActivityLog } from '@definitions/account';

export default () => {
    const { hash } = useLocationHash();
    const { clearAndAddHttpError } = useFlashKey('account');
    const [filters, setFilters] = useState<ActivityLogFilters>({ page: 1, sorts: { timestamp: -1 } });
    const { data, isValidating, error } = useActivityLogs(filters, {
        revalidateOnMount: true,
        revalidateOnFocus: false,
    });

    // Client-side filter states
    // Note: These filters work on the current page of data loaded from the API.
    // Users can paginate through all results and apply filters to each page.
    // This is a frontend-only implementation to avoid backend changes.
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedUser, setSelectedUser] = useState<string>('');
    const [selectedEventType, setSelectedEventType] = useState<string>('');
    const [sortOrder, setSortOrder] = useState<'newest' | 'oldest'>('newest');

    const enabled: boolean = useStoreState(state => state.settings.data!.activity.enabled.admin);

    if (!enabled) return <></>;

    useEffect(() => {
        setFilters(value => ({ ...value, filters: { ip: hash.ip, event: hash.event } }));
    }, [hash]);

    useEffect(() => {
        clearAndAddHttpError(error);
    }, [error]);

    // Extract unique users from activity data
    const uniqueUsers = useMemo(() => {
        if (!data?.items) return [];
        const users = new Map<string, { uuid: string; username: string }>();
        data.items.forEach(activity => {
            if (activity.relationships.actor) {
                const actor = activity.relationships.actor;
                users.set(actor.uuid, { uuid: actor.uuid, username: actor.username });
            }
        });
        return Array.from(users.values()).sort((a, b) => a.username.localeCompare(b.username));
    }, [data?.items]);

    // Extract unique event types
    const uniqueEventTypes = useMemo(() => {
        if (!data?.items) return [];
        const events = new Set<string>();
        data.items.forEach(activity => {
            events.add(activity.event);
        });
        return Array.from(events).sort();
    }, [data?.items]);

    // Client-side filtering and sorting
    const filteredActivities = useMemo(() => {
        if (!data?.items) return [];

        let filtered = [...data.items];

        // Search filter
        if (searchQuery.trim()) {
            const query = searchQuery.toLowerCase();
            filtered = filtered.filter(activity => {
                const actor = activity.relationships.actor;
                const actorName = actor?.username?.toLowerCase() || '';
                const actorEmail = actor?.email?.toLowerCase() || '';
                const event = (activity.description || activity.event).toLowerCase();
                const ip = activity.ip?.toLowerCase() || '';
                const serverId = String(activity.properties.server_id || '').toLowerCase();
                const serverName = String(activity.properties.server || '').toLowerCase();

                return (
                    actorName.includes(query) ||
                    actorEmail.includes(query) ||
                    event.includes(query) ||
                    ip.includes(query) ||
                    serverId.includes(query) ||
                    serverName.includes(query)
                );
            });
        }

        // User filter
        if (selectedUser) {
            filtered = filtered.filter(
                activity => activity.relationships.actor?.uuid === selectedUser
            );
        }

        // Event type filter
        if (selectedEventType) {
            filtered = filtered.filter(activity => activity.event === selectedEventType);
        }

        // Sort
        filtered.sort((a, b) => {
            const timeA = a.timestamp.getTime();
            const timeB = b.timestamp.getTime();
            return sortOrder === 'newest' ? timeB - timeA : timeA - timeB;
        });

        return filtered;
    }, [data?.items, searchQuery, selectedUser, selectedEventType, sortOrder]);

    // Debounced search handler
    const handleSearchChange = useMemo(
        () =>
            debounce((value: string) => {
                setSearchQuery(value);
            }, 300),
        []
    );

    // Cleanup debounced function on unmount
    useEffect(() => {
        return () => {
            handleSearchChange.clear && handleSearchChange.clear();
        };
    }, [handleSearchChange]);

    const clearFilters = () => {
        setSearchQuery('');
        setSelectedUser('');
        setSelectedEventType('');
        setSortOrder('newest');
        setFilters(value => ({ ...value, filters: {} }));
    };

    const hasActiveFilters =
        searchQuery.trim() !== '' ||
        selectedUser !== '' ||
        selectedEventType !== '' ||
        sortOrder !== 'newest' ||
        filters.filters?.event ||
        filters.filters?.ip;

    return (
        <AdminContentBlock title={'Admin Activity'}>
            <FlashMessageRender byKey={'admin:activity'} className={'mb-4'} />
            <div className={'mb-6 flex w-full flex-row items-center'}>
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

            {/* Filter Toolbar */}
            <div className={'mb-4 space-y-3'}>
                <div className={'grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4'}>
                    {/* Search Input */}
                    <div className={'relative'}>
                        <div className={'pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3'}>
                            <SearchIcon className={'h-5 w-5 text-neutral-400'} />
                        </div>
                        <Input
                            type={'text'}
                            placeholder={'Search activity...'}
                            className={'pl-10'}
                            defaultValue={searchQuery}
                            onChange={e => handleSearchChange(e.currentTarget.value)}
                        />
                    </div>

                    {/* User Filter */}
                    <Select
                        value={selectedUser}
                        onChange={e => setSelectedUser(e.currentTarget.value)}
                    >
                        <option value={''}>All Users</option>
                        {uniqueUsers.map(user => (
                            <option key={user.uuid} value={user.uuid}>
                                {user.username}
                            </option>
                        ))}
                    </Select>

                    {/* Event Type Filter */}
                    <Select
                        value={selectedEventType}
                        onChange={e => setSelectedEventType(e.currentTarget.value)}
                    >
                        <option value={''}>All Event Types</option>
                        {uniqueEventTypes.map(event => (
                            <option key={event} value={event}>
                                {event}
                            </option>
                        ))}
                    </Select>

                    {/* Sort Order */}
                    <Select
                        value={sortOrder}
                        onChange={e => setSortOrder(e.currentTarget.value as 'newest' | 'oldest')}
                    >
                        <option value={'newest'}>Newest First</option>
                        <option value={'oldest'}>Oldest First</option>
                    </Select>
                </div>

                {/* Clear Filters Button */}
                {hasActiveFilters && (
                    <div className={'flex justify-end'}>
                        <Link
                            to={'#'}
                            className={classNames(btnStyles.button, btnStyles.text, 'w-full sm:w-auto')}
                            onClick={clearFilters}
                        >
                            Clear All Filters <XCircleIcon className={'ml-2 h-4 w-4'} />
                        </Link>
                    </div>
                )}
            </div>

            {/* Activity List */}
            {isValidating ? (
                <Spinner centered />
            ) : (
                <div className={'bg-slate-700'}>
                    {filteredActivities.length > 0 ? (
                        <>
                            {filteredActivities.map(activity => (
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
                    ) : data?.items && data.items.length > 0 ? (
                        <div className={'py-12 text-center'}>
                            <p className={'text-lg font-medium text-neutral-50'}>No activity found</p>
                            <p className={'mt-1 text-sm text-neutral-400'}>
                                Try adjusting your filters to see more results.
                            </p>
                        </div>
                    ) : (
                        <div className={'py-12 text-center'}>
                            <p className={'text-lg font-medium text-neutral-50'}>No activity found</p>
                            <p className={'mt-1 text-sm text-neutral-400'}>
                                There are no admin logs available at this time.
                            </p>
                        </div>
                    )}
                </div>
            )}
            {data && data.items.length > 0 && (
                <PaginationFooter
                    pagination={data.pagination}
                    onPageSelect={page => setFilters(value => ({ ...value, page }))}
                />
            )}
        </AdminContentBlock>
    );
};
