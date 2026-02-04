import Pill, { PillStatus } from '@/elements/Pill';
import { useGetOrders, Context as OrderContext } from '@/api/routes/admin/billing/orders';
import AdminTable, {
    ContentWrapper,
    Pagination,
    TableHead,
    TableHeader,
    TableBody,
    TableRow,
    Loading,
    NoItems,
    useTableHooks,
} from '@/elements/AdminTable';
import CopyOnClick from '@/elements/CopyOnClick';
import tw from 'twin.macro';
import { useContext, useEffect, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import { formatDistanceToNowStrict } from 'date-fns';
import Spinner from '@/elements/Spinner';
import { OrderFilters, PaymentProcessor } from '@/api/routes/admin/billing/types';
import PaymentProcessorBadge from '@/components/elements/PaymentProcessorBadge';
import PaymentProcessorFilter from '@/components/elements/PaymentProcessorFilter';
import OrderInspectorModal from '@/components/elements/OrderInspectorModal';
import { Order } from '@definitions/admin/models';

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

function getColor(index: number) {
    if (index >= 50) return 'danger';
    if (index >= 25) return 'warn';
    else return 'success';
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
    const [paymentProcessor, setPaymentProcessor] = useState<PaymentProcessor | null>(null);
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

    const onSearch = (query: string): Promise<void> => {
        return new Promise(resolve => {
            if (query.length < 2) {
                setFilters(paymentProcessor ? { payment_processor: paymentProcessor } : null);
            } else {
                if (!minimal) {
                    setFilters({ 
                        description: query,
                        ...(paymentProcessor ? { payment_processor: paymentProcessor } : {})
                    });
                }
            }
            return resolve();
        });
    };

    const handleProcessorChange = (processor: PaymentProcessor | null) => {
        setPaymentProcessor(processor);
        setFilters(processor ? { payment_processor: processor } : null);
    };

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
                        <div css={tw`mb-4`}>
                            <PaymentProcessorFilter 
                                value={paymentProcessor} 
                                onChange={handleProcessorChange}
                            />
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
                                            <TableRow 
                                                key={order.id}
                                                className={`cursor-pointer transition-colors ${!minimal ? getStatusRowClass(order.status) : 'hover:bg-neutral-700'}`}
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
                                                        <span css={tw`text-white font-medium`}>{order.name}</span>
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
                                            </TableRow>
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
