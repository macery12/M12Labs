import Pill, { PillStatus } from '@/elements/Pill';
import PageContentBlock from '@/elements/PageContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { useContext, useEffect, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import AdminTable, {
    ContentWrapper,
    Loading,
    NoItems,
    Pagination,
    TableBody,
    TableHead,
    TableHeader,
    useTableHooks,
} from '@/elements/AdminTable';
import CopyOnClick from '@/elements/CopyOnClick';
import { formatDistanceToNowStrict } from 'date-fns';
import { useGetOrders } from '@/api/routes/account/billing/orders';
import { Context as OrderContext } from '@/api/routes/account/billing/orders/index';
import { OrderFilters, PaymentProcessor } from '@/api/routes/account/billing/orders/types';
import ScopedAlert from '@/components/account/ScopedAlert';
import PaymentProcessorBadge from '@/components/elements/PaymentProcessorBadge';
import PaymentProcessorFilter from '@/components/elements/PaymentProcessorFilter';
import OrderInspectorModal from '@/components/elements/OrderInspectorModal';
import { Order } from '@definitions/account/billing/models';
import tw from 'twin.macro';

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

function OrderTable() {
    const { data: orders, error } = useGetOrders();
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { setPage, setFilters, sort, setSort, sortDirection } = useContext(OrderContext);
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
                setFilters({ 
                    name: query,
                    ...(paymentProcessor ? { payment_processor: paymentProcessor } : {})
                });
            }
            return resolve();
        });
    };

    const handleProcessorChange = (processor: PaymentProcessor | null) => {
        setPaymentProcessor(processor);
        setFilters(processor ? { payment_processor: processor } : null);
    };

    useEffect(() => {
        clearFlashes();

        if (error) {
            clearAndAddHttpError({ key: 'billing:orders', error });
        }
    }, [error]);

    return (
        <PageContentBlock>
            <ScopedAlert scope="billing" position="top-center" />
            <div className={'mt-8 mb-12 text-3xl font-bold lg:text-5xl'}>
                Billing Activity
                <p className={'mt-1 text-sm font-normal text-gray-400'}>
                    View and manage the active and previous subscriptions you&apos;ve created.
                </p>
                <FlashMessageRender byKey={'billing:orders'} className={'mt-4'} />
            </div>
            <AdminTable>
                <ContentWrapper onSearch={onSearch}>
                    <div css={tw`mb-4`}>
                        <PaymentProcessorFilter 
                            value={paymentProcessor} 
                            onChange={handleProcessorChange}
                        />
                    </div>
                    <Pagination data={orders} onPageSelect={setPage}>
                        <div className={`overflow-x-auto`}>
                            <table className={`w-full table-auto`}>
                                <TableHead>
                                    <TableHeader
                                        name={'Order ID'}
                                        direction={sort === 'id' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('id')}
                                    />
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
                                                className={`h-12 cursor-pointer transition-colors ${getStatusRowClass(order.status)}`}
                                                onClick={() => openInspector(order)}
                                            >
                                                <td className={`whitespace-nowrap px-6 py-4 text-left text-sm`}>
                                                    <CopyOnClick text={order.id.toString()}>
                                                        <code className={`rounded bg-neutral-900 py-1 px-2 font-mono text-gray-300 hover:text-white transition-colors`}>
                                                            {order.id}
                                                        </code>
                                                    </CopyOnClick>
                                                </td>
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
            {selectedOrder && (
                <OrderInspectorModal
                    order={selectedOrder}
                    isOpen={isInspectorOpen}
                    onClose={closeInspector}
                    isAdmin={false}
                />
            )}
        </PageContentBlock>
    );
}

export default () => {
    const hooks = useTableHooks<OrderFilters>();

    return (
        <OrderContext.Provider value={hooks}>
            <OrderTable />
        </OrderContext.Provider>
    );
};
