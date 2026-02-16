import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
    getEmailLogs,
    getTemplateKeys,
    type EmailLog,
    type PaginatedResponse,
} from '@/api/routes/admin/email';
import useFlash from '@/plugins/useFlash';
import Spinner from '@/elements/Spinner';
import { Button } from '@/elements/button';
import tw from 'twin.macro';
import styled from 'styled-components';
import EmailLogDetailModal from './EmailLogDetailModal';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faSearch,
    faFilter,
    faRedo,
    faCheckCircle,
    faTimesCircle,
    faClock,
    faBan,
} from '@fortawesome/free-solid-svg-icons';

const Table = styled.table`
    ${tw`w-full table-auto`}
`;

const Th = styled.th`
    ${tw`px-4 py-3 text-left text-xs font-medium text-neutral-400 uppercase tracking-wider border-b border-neutral-700`}
`;

const Td = styled.td`
    ${tw`px-4 py-3 text-sm border-b border-neutral-700`}
`;

const StatusBadge = styled.span<{ status: string }>`
    ${tw`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium`}
    ${(props) => {
        switch (props.status) {
            case 'sent':
                return tw`bg-green-900 text-green-300`;
            case 'failed':
                return tw`bg-red-900 text-red-300`;
            case 'deferred':
                return tw`bg-yellow-900 text-yellow-300`;
            case 'blocked':
                return tw`bg-yellow-900 text-yellow-300`;
            case 'skipped':
                return tw`bg-neutral-700 text-neutral-300`;
            default:
                return tw`bg-neutral-700 text-neutral-300`;
        }
    }}
`;

const FilterContainer = styled.div`
    ${tw`mb-6 p-4 bg-neutral-800 rounded-lg border border-neutral-700`}
`;

const FilterGrid = styled.div`
    ${tw`grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4`}
`;

const Select = styled.select`
    ${tw`w-full px-3 py-2 bg-neutral-900 border border-neutral-700 rounded text-sm text-neutral-300 focus:outline-none focus:ring-2 focus:ring-blue-500`}
`;

const Input = styled.input`
    ${tw`w-full px-3 py-2 bg-neutral-900 border border-neutral-700 rounded text-sm text-neutral-300 focus:outline-none focus:ring-2 focus:ring-blue-500`}
`;

const getStatusIcon = (status: string) => {
    switch (status) {
        case 'sent':
            return faCheckCircle;
        case 'failed':
            return faTimesCircle;
        case 'deferred':
            return faClock;
        case 'blocked':
        case 'skipped':
            return faBan;
        default:
            return faCheckCircle;
    }
};

export default () => {
    const [searchParams, setSearchParams] = useSearchParams();
    const [loading, setLoading] = useState(true);
    const [logs, setLogs] = useState<PaginatedResponse<EmailLog> | null>(null);
    const [templateKeys, setTemplateKeys] = useState<string[]>([]);
    const [selectedLog, setSelectedLog] = useState<number | null>(null);
    const [showFilters, setShowFilters] = useState(true);
    const { addFlash } = useFlash();

    // Filter states
    const [filters, setFilters] = useState({
        status: searchParams.get('status') || '',
        template_key: searchParams.get('template_key') || '',
        recipient: searchParams.get('recipient') || '',
        only_failures: searchParams.get('only_failures') === 'true',
        date_from: searchParams.get('date_from') || '',
        date_to: searchParams.get('date_to') || '',
        page: parseInt(searchParams.get('page') || '1'),
    });

    useEffect(() => {
        loadData();
        loadTemplateKeys();
    }, [filters]);

    const loadData = async () => {
        setLoading(true);
        try {
            const data = await getEmailLogs(filters);
            setLogs(data);
        } catch (error: any) {
            addFlash({
                key: 'email:activity',
                type: 'error',
                message: error.message || 'Failed to load email logs',
            });
        } finally {
            setLoading(false);
        }
    };

    const loadTemplateKeys = async () => {
        try {
            const { template_keys } = await getTemplateKeys();
            setTemplateKeys(template_keys);
        } catch (error) {
            // Silently fail
        }
    };

    const updateFilter = (key: string, value: any) => {
        const newFilters = { ...filters, [key]: value, page: 1 };
        setFilters(newFilters);

        // Update URL params
        const newParams = new URLSearchParams();
        Object.entries(newFilters).forEach(([k, v]) => {
            if (v !== '' && v !== false) {
                newParams.set(k, String(v));
            }
        });
        setSearchParams(newParams);
    };

    const clearFilters = () => {
        const newFilters = {
            status: '',
            template_key: '',
            recipient: '',
            only_failures: false,
            date_from: '',
            date_to: '',
            page: 1,
        };
        setFilters(newFilters);
        setSearchParams(new URLSearchParams());
    };

    const quickDateRange = (days: number) => {
        const now = new Date();
        const from = new Date(now.getTime() - days * 24 * 60 * 60 * 1000);
        updateFilter('date_from', from.toISOString().split('T')[0]);
        updateFilter('date_to', now.toISOString().split('T')[0]);
    };

    const formatDate = (dateString: string) => {
        const date = new Date(dateString);
        return date.toLocaleString();
    };

    const goToPage = (page: number) => {
        updateFilter('page', page);
    };

    return (
        <div>
            <div className='flex items-center justify-between mb-4'>
                <h2 className='text-2xl font-bold'>Email Activity Log</h2>
                <Button onClick={() => setShowFilters(!showFilters)} variant='secondary' size='sm'>
                    <FontAwesomeIcon icon={faFilter} className='mr-2' />
                    {showFilters ? 'Hide' : 'Show'} Filters
                </Button>
            </div>

            {showFilters && (
                <FilterContainer>
                    <div className='flex items-center justify-between mb-4'>
                        <h3 className='text-lg font-semibold'>Filters</h3>
                        <Button onClick={clearFilters} variant='text' size='sm'>
                            Clear All
                        </Button>
                    </div>

                    <FilterGrid>
                        <div>
                            <label className='block text-sm font-medium mb-1 text-gray-400'>Status</label>
                            <Select value={filters.status} onChange={(e) => updateFilter('status', e.target.value)}>
                                <option value=''>All Statuses</option>
                                <option value='sent'>Sent</option>
                                <option value='failed'>Failed</option>
                                <option value='deferred'>Deferred</option>
                                <option value='blocked'>Blocked</option>
                                <option value='skipped'>Skipped</option>
                            </Select>
                        </div>

                        <div>
                            <label className='block text-sm font-medium mb-1 text-gray-400'>Template</label>
                            <Select
                                value={filters.template_key}
                                onChange={(e) => updateFilter('template_key', e.target.value)}
                            >
                                <option value=''>All Templates</option>
                                {templateKeys.map((key) => (
                                    <option key={key} value={key}>
                                        {key}
                                    </option>
                                ))}
                            </Select>
                        </div>

                        <div>
                            <label className='block text-sm font-medium mb-1 text-gray-400'>Recipient</label>
                            <Input
                                type='text'
                                placeholder='Search recipient...'
                                value={filters.recipient}
                                onChange={(e) => updateFilter('recipient', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className='block text-sm font-medium mb-1 text-gray-400'>Quick Date Range</label>
                            <div className='flex gap-2'>
                                <Button onClick={() => quickDateRange(1)} variant='secondary' size='sm'>
                                    24h
                                </Button>
                                <Button onClick={() => quickDateRange(7)} variant='secondary' size='sm'>
                                    7d
                                </Button>
                                <Button onClick={() => quickDateRange(30)} variant='secondary' size='sm'>
                                    30d
                                </Button>
                            </div>
                        </div>

                        <div>
                            <label className='block text-sm font-medium mb-1 text-gray-400'>Date From</label>
                            <Input
                                type='date'
                                value={filters.date_from}
                                onChange={(e) => updateFilter('date_from', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className='block text-sm font-medium mb-1 text-gray-400'>Date To</label>
                            <Input
                                type='date'
                                value={filters.date_to}
                                onChange={(e) => updateFilter('date_to', e.target.value)}
                            />
                        </div>

                        <div className='flex items-end'>
                            <label className='flex items-center text-sm'>
                                <input
                                    type='checkbox'
                                    checked={filters.only_failures}
                                    onChange={(e) => updateFilter('only_failures', e.target.checked)}
                                    className='mr-2'
                                />
                                Only show failures
                            </label>
                        </div>
                    </FilterGrid>
                </FilterContainer>
            )}

            {loading ? (
                <div className='flex items-center justify-center py-12'>
                    <Spinner size='large' />
                </div>
            ) : logs && logs.data.length > 0 ? (
                <>
                    <div className='bg-neutral-800 rounded-lg border border-gray-700 overflow-hidden'>
                        <div className='overflow-x-auto'>
                            <Table>
                                <thead>
                                    <tr>
                                        <Th>Status</Th>
                                        <Th>Timestamp</Th>
                                        <Th>Recipient</Th>
                                        <Th>Template</Th>
                                        <Th>Provider</Th>
                                        <Th>Attempts</Th>
                                        <Th>Actions</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {logs.data.map((log) => (
                                        <tr key={log.id} className='hover:bg-neutral-800 transition-colors'>
                                            <Td>
                                                <StatusBadge status={log.status}>
                                                    <FontAwesomeIcon icon={getStatusIcon(log.status)} className='mr-1' />
                                                    {log.status}
                                                </StatusBadge>
                                            </Td>
                                            <Td className='text-gray-400'>{formatDate(log.created_at)}</Td>
                                            <Td>
                                                <div className='flex items-center'>
                                                    <span className='text-neutral-300'>{log.to}</span>
                                                    {log.user && (
                                                        <span className='ml-2 text-xs text-gray-500'>
                                                            ({log.user.username})
                                                        </span>
                                                    )}
                                                </div>
                                            </Td>
                                            <Td>
                                                <code className='text-xs bg-gray-900 px-2 py-1 rounded text-blue-400'>
                                                    {log.template_key || 'custom'}
                                                </code>
                                            </Td>
                                            <Td className='text-gray-400'>{log.provider}</Td>
                                            <Td>
                                                <span
                                                    className={
                                                        log.attempt_count > 1 ? 'text-yellow-400' : 'text-gray-400'
                                                    }
                                                >
                                                    {log.attempt_count}
                                                </span>
                                            </Td>
                                            <Td>
                                                <Button
                                                    onClick={() => setSelectedLog(log.id)}
                                                    variant='secondary'
                                                    size='sm'
                                                >
                                                    View Details
                                                </Button>
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </div>
                    </div>

                    {/* Pagination */}
                    {logs.last_page > 1 && (
                        <div className='flex items-center justify-center gap-2 mt-6'>
                            <Button
                                onClick={() => goToPage(filters.page - 1)}
                                disabled={filters.page === 1}
                                variant='secondary'
                                size='sm'
                            >
                                Previous
                            </Button>
                            <span className='text-sm text-gray-400'>
                                Page {logs.current_page} of {logs.last_page}
                            </span>
                            <Button
                                onClick={() => goToPage(filters.page + 1)}
                                disabled={filters.page === logs.last_page}
                                variant='secondary'
                                size='sm'
                            >
                                Next
                            </Button>
                        </div>
                    )}
                </>
            ) : (
                <div className='bg-neutral-800 rounded-lg border border-gray-700 py-12 text-center'>
                    <p className='text-gray-400 text-lg'>No email logs found</p>
                    <p className='text-gray-500 text-sm mt-2'>Try adjusting your filters</p>
                </div>
            )}

            {selectedLog && (
                <EmailLogDetailModal logId={selectedLog} onClose={() => setSelectedLog(null)} />
            )}
        </div>
    );
};
