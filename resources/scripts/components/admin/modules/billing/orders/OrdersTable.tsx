import Pill, { PillStatus } from '@/elements/Pill';
import { useGetOrders, Context as OrderContext } from '@/api/routes/admin/billing/orders';
import AdminTable, {
    ContentWrapper,
    Pagination,
    TableHead,
    TableHeader,
    TableBody,
    Loading,
    NoItems,
    useTableHooks,
} from '@/elements/AdminTable';
import CopyOnClick from '@/elements/CopyOnClick';
import tw from 'twin.macro';
import { useContext, useEffect, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import { formatDistanceToNowStrict } from 'date-fns';
import { OrderFilters, PaymentProcessor, OrderStatus } from '@/api/routes/admin/billing/types';
import PaymentProcessorBadge from '@/components/elements/PaymentProcessorBadge';
import PaymentProcessorFilter from '@/components/elements/PaymentProcessorFilter';
import StatusFilter from '@/components/elements/StatusFilter';
import OrderTypeFilter from '@/components/elements/OrderTypeFilter';
import AmountRangeFilter from '@/components/elements/AmountRangeFilter';
import DateRangeFilter from '@/components/elements/DateRangeFilter';
import OrderInspectorModal from '@/components/elements/OrderInspectorModal';
import { Order } from '@definitions/admin/models';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTimes } from '@fortawesome/free-solid-svg-icons';

export function format(date: number): string {
    let prefix = 'th';

    switch (date) {
        case 1:
        case 21:
        case 31:
            prefix = 'st';
            break;
        case 2:
        case 22:
            prefix = 'nd';
            break;
        case 3:
        case 23:
            prefix = 'rd';
            break;
        default:
            break;
    }

    return `${date}${prefix}`;
}

export function type(state: string): PillStatus {
    switch (state) {
        case 'processed':
            return 'success';
        case 'failed':
            return 'danger';
        case 'pending':
            return 'warn';
        default:
            return 'unknown';
    }
}

function getStatusRowClass(status: string): string {
    switch (status) {
        case 'failed':
            return 'bg-red-500/5 hover:bg-red-500/10';
        case 'pending':
            return 'bg-yellow-500/5 hover:bg-yellow-500/10';
        case 'processed':
            return 'bg-green-500/5 hover:bg-green-500/10';
        default:
            return 'hover:bg-neutral-700';
    }
}

function OrderTable({ minimal }: { minimal?: boolean }) {
    const { data: orders, error } = useGetOrders();
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { setSort, sort, setPage, sortDirection, setFilters } = useContext(OrderContext);
    
    // Filter states
    const [paymentProcessor, setPaymentProcessor] = useState<PaymentProcessor | null>(null);
    const [status, setStatus] = useState<OrderStatus | null>(null);
    const [orderType, setOrderType] = useState<string | null>(null);
    const [minAmount, setMinAmount] = useState<number | null>(null);
    const [maxAmount, setMaxAmount] = useState<number | null>(null);
    const [startDate, setStartDate] = useState<string | null>(null);
    const [endDate, setEndDate] = useState<string | null>(null);
    const [searchQuery, setSearchQuery] = useState<string>('');
    
    const [selectedOrder, setSelectedOrder] = useState<Order | null>(null);
    const [isInspectorOpen, setIsInspectorOpen] = useState(false);

    const openInspector = (order: Order) => {
        setSelectedOrder(order);
        setIsInspectorOpen(true);
    };

    const closeInspector = () => {
        setIsInspectorOpen(false);
        setTimeout(() => setSelectedOrder(null), 300);
    };

    // Build filters object
    const buildFilters = (): OrderFilters | null => {
        const filters: OrderFilters = {};
        let hasFilters = false;

        if (paymentProcessor) {
            filters.payment_processor = paymentProcessor;
            hasFilters = true;
        }
        if (status) {
            filters.status = status;
            hasFilters = true;
        }
        if (orderType) {
            filters.type = orderType;
            hasFilters = true;
        }
        if (minAmount !== null) {
            filters.min_amount = minAmount;
            hasFilters = true;
        }
        if (maxAmount !== null) {
            filters.max_amount = maxAmount;
            hasFilters = true;
        }
        if (startDate) {
            filters.start_date = startDate;
            hasFilters = true;
        }
        if (endDate) {
            filters.end_date = endDate;
            hasFilters = true;
        }
        if (searchQuery && searchQuery.length >= 2) {
            filters.search = searchQuery;
            hasFilters = true;
        }

        return hasFilters ? filters : null;
    };

    // Apply filters when any filter changes
    useEffect(() => {
        if (!minimal) {
            setFilters(buildFilters());
        }
    }, [paymentProcessor, status, orderType, minAmount, maxAmount, startDate, endDate, searchQuery, minimal]);

    const onSearch = (query: string): Promise<void> => {
        return new Promise(resolve => {
            setSearchQuery(query);
            return resolve();
        });
    };

    const clearFilters = () => {
        setPaymentProcessor(null);
        setStatus(null);
        setOrderType(null);
        setMinAmount(null);
        setMaxAmount(null);
        setStartDate(null);
        setEndDate(null);
        setSearchQuery('');
    };

    const hasActiveFilters = paymentProcessor || status || orderType || minAmount || maxAmount || startDate || endDate || searchQuery;

    useEffect(() => {
        if (!error) {
            clearFlashes('admin:billing:orders');
            return;
        }

        clearAndAddHttpError({ key: 'admin:billing:orders', error });
    }, [error]);

    return (
        <>
            <AdminTable>
                <ContentWrapper onSearch={onSearch}>
                    {!minimal && (
                        <div css={tw`mb-6 bg-neutral-900/50 rounded-lg p-4 space-y-4`}>
                            <div css={tw`flex items-center justify-between mb-2`}>
                                <h3 css={tw`text-sm font-semibold text-white`}>Filters</h3>
                                {hasActiveFilters && (
                                    <button
                                        onClick={clearFilters}
                                        css={tw`text-sm text-red-400 hover:text-red-300 transition-colors flex items-center gap-1`}
                                    >
                                        <FontAwesomeIcon icon={faTimes} />
                                        Clear All Filters
                                    </button>
                                )}
                            </div>
                            <div css={tw`grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4`}>
                                <PaymentProcessorFilter 
                                    value={paymentProcessor} 
                                    onChange={setPaymentProcessor}
                                />
                                <StatusFilter
                                    value={status}
                                    onChange={setStatus}
                                />
                                <OrderTypeFilter
                                    value={orderType as any}
                                    onChange={setOrderType as any}
                                />
                                <AmountRangeFilter
                                    minValue={minAmount}
                                    maxValue={maxAmount}
                                    onMinChange={setMinAmount}
                                    onMaxChange={setMaxAmount}
                                />
                                <DateRangeFilter
                                    startDate={startDate}
                                    endDate={endDate}
                                    onStartDateChange={setStartDate}
                                    onEndDateChange={setEndDate}
                                />
                            </div>
                        </div>
                    )}
                    <Pagination data={orders} onPageSelect={setPage}>
                        <div css={tw`overflow-x-auto`}>
                            <table css={tw`w-full table-auto`}>
                                <TableHead>
                                    {!minimal && (
                                        <TableHeader
                                            name={'Order ID'}
                                            direction={sort === 'id' ? (sortDirection ? 1 : 2) : null}
                                            onClick={() => setSort('id')}
                                        />
                                    )}
                                    {!minimal && <TableHeader name={'Customer'} />}
                                    <TableHeader name={'Product'} />
                                    <TableHeader name={'Provider'} />
                                    <TableHeader name={'Status'} />
                                    <TableHeader
                                        name={'Amount'}
                                        direction={sort === 'total' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('total')}
                                    />
                                    <TableHeader
                                        name={'Created'}
                                        direction={sort === 'created_at' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('created_at')}
                                    />
                                </TableHead>
                                <TableBody>
                                    {orders !== undefined &&
                                        orders.items.length > 0 &&
                                        orders.items.map(order => (
                                            <tr 
                                                key={order.id}
                                                className={`h-12 cursor-pointer transition-colors ${!minimal ? getStatusRowClass(order.status) : 'hover:bg-neutral-700'}`}
                                                onClick={() => !minimal && openInspector(order)}
                                            >
                                                {!minimal && (
                                                    <td css={tw`px-6 py-4 text-sm text-left whitespace-nowrap`}>
                                                        <CopyOnClick text={order.id.toString()}>
                                                            <code css={tw`font-mono bg-neutral-900 rounded py-1 px-2 text-gray-300 hover:text-white transition-colors`}>
                                                                {order.id}
                                                            </code>
                                                        </CopyOnClick>
                                                    </td>
                                                )}
                                                {!minimal && (
                                                    <td css={tw`px-6 py-4 text-sm text-gray-400`}>
                                                        User #{order.user_id}
                                                    </td>
                                                )}
                                                <td className={'px-6 py-4'}>
                                                    <div css={tw`flex items-center gap-2`}>
                                                        <span css={tw`text-white font-medium`}>
                                                            {order.server_name || order.name}
                                                        </span>
                                                        {order.type === 'ren' && (
                                                            <Pill size="small" type="info">REN</Pill>
                                                        )}
                                                        {order.type === 'new' && (
                                                            <Pill size="small" type="success">NEW</Pill>
                                                        )}
                                                        {order.type === 'upg' && (
                                                            <Pill size="small" type="warn">UPG</Pill>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className={'px-6 py-4'}>
                                                    <PaymentProcessorBadge 
                                                        processor={order.payment_processor} 
                                                        size="small"
                                                    />
                                                </td>
                                                <td className={'px-6 py-4'}>
                                                    <Pill size={'small'} type={type(order.status)}>
                                                        {order.status}
                                                    </Pill>
                                                </td>
                                                <td className={'px-6 py-4 font-bold text-white'}>
                                                    ${order.total.toFixed(2)}
                                                    {order.type === 'ren' && <span css={tw`text-sm text-gray-400 ml-1`}>/mo</span>}
                                                </td>
                                                <td className={'px-6 py-4 text-gray-400'}>
                                                    {formatDistanceToNowStrict(order.created_at, { addSuffix: true })}
                                                </td>
                                            </tr>
                                        ))}
                                </TableBody>
                            </table>
                            {orders === undefined ? <Loading /> : orders.items.length < 1 ? <NoItems /> : null}
                        </div>
                    </Pagination>
                </ContentWrapper>
            </AdminTable>

            {/* Order Inspector Modal */}
            {!minimal && selectedOrder && (
                <OrderInspectorModal
                    order={selectedOrder}
                    isOpen={isInspectorOpen}
                    onClose={closeInspector}
                    isAdmin={true}
                />
            )}
        </>
    );
}

export default ({ name, minimal }: { name?: string; minimal?: boolean }) => {
    const hooks = useTableHooks<OrderFilters>({ name: name });

    return (
        <OrderContext.Provider value={hooks}>
            <OrderTable minimal={minimal} />
        </OrderContext.Provider>
    );
};
