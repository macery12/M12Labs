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
    TableRow,
    useTableHooks,
} from '@/elements/AdminTable';
import CopyOnClick from '@/elements/CopyOnClick';
import { formatDistanceToNowStrict } from 'date-fns';
import { useGetOrders } from '@/api/routes/account/billing/orders';
import { Context as OrderContext } from '@/api/routes/account/billing/orders/index';
import { OrderFilters } from '@/api/routes/account/billing/orders/types';
import ScopedAlert from '@/components/account/ScopedAlert';
import PaymentProcessorBadge from '@/components/elements/PaymentProcessorBadge';
import OrderPaymentDetails from '@/components/elements/OrderPaymentDetails';
import { Order } from '@definitions/account/billing/models';
import tw from 'twin.macro';
import { faChevronDown, faChevronUp } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

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

function OrderTable() {
    const { data: orders, error } = useGetOrders();
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { setPage, setFilters, sort, setSort, sortDirection } = useContext(OrderContext);
    const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set());

    const toggleRow = (orderId: number) => {
        setExpandedRows(prev => {
            const newSet = new Set(prev);
            if (newSet.has(orderId)) {
                newSet.delete(orderId);
            } else {
                newSet.add(orderId);
            }
            return newSet;
        });
    };

    const onSearch = (query: string): Promise<void> => {
        return new Promise(resolve => {
            if (query.length < 2) {
                setFilters(null);
            } else {
                setFilters({ name: query });
            }
            return resolve();
        });
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
                    <Pagination data={orders} onPageSelect={setPage}>
                        <div className={`overflow-x-auto`}>
                            <table className={`w-full table-auto`}>
                                <TableHead>
                                    <TableHeader
                                        name={'ID'}
                                        direction={sort === 'id' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('id')}
                                    />
                                    <TableHeader
                                        name={'Total Price'}
                                        direction={sort === 'total' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('total')}
                                    />
                                    <TableHeader name={'Description'} />
                                    <TableHeader
                                        name={'Created At'}
                                        direction={sort === 'created_at' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('created_at')}
                                    />
                                    <TableHeader name={'Payment State'} />
                                    <TableHeader
                                        name={'Order Type'}
                                        direction={sort === 'type' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('type')}
                                    />
                                    <TableHeader name={'Payment Provider'} />
                                    <TableHeader name={''} />
                                </TableHead>
                                <TableBody>
                                    {orders !== undefined &&
                                        orders.items.length > 0 &&
                                        orders.items.map(order => (
                                            <>
                                                <TableRow key={order.id}>
                                                    <td
                                                        className={`whitespace-nowrap px-6 text-left text-sm text-neutral-200`}
                                                    >
                                                        <CopyOnClick text={order.id.toString()}>
                                                            <code className={`rounded bg-neutral-900 py-1 px-2 font-mono`}>
                                                                {order.id}
                                                            </code>
                                                        </CopyOnClick>
                                                    </td>
                                                    <td className={'px-6 py-4 font-bold text-white'}>${order.total.toFixed(2)}</td>
                                                    <td className={'px-6 py-4'}>
                                                        {order.name.slice(0, 8)} {order.description}
                                                    </td>
                                                    <td className={'px-6 py-4'}>
                                                        {formatDistanceToNowStrict(order.created_at, { addSuffix: true })}
                                                    </td>
                                                    <td className={'px-6 py-4 text-left'}>
                                                        <Pill size={'small'} type={type(order.status)}>
                                                            {order.status}
                                                        </Pill>
                                                    </td>
                                                    <td className={'py-4 pr-6 text-center'}>
                                                        <Pill
                                                            size={'small'}
                                                            type={order.type === 'new' ? 'success' : 'info'}
                                                        >
                                                            {order.type.toUpperCase()}
                                                        </Pill>
                                                    </td>
                                                    <td className={'px-6 py-4'}>
                                                        <PaymentProcessorBadge 
                                                            processor={order.payment_processor} 
                                                            size="small"
                                                        />
                                                    </td>
                                                    <td className={'px-6 py-4 text-center'}>
                                                        <button
                                                            onClick={() => toggleRow(order.id)}
                                                            css={tw`text-gray-400 hover:text-white transition-colors`}
                                                        >
                                                            <FontAwesomeIcon 
                                                                icon={expandedRows.has(order.id) ? faChevronUp : faChevronDown} 
                                                            />
                                                        </button>
                                                    </td>
                                                </TableRow>
                                                {expandedRows.has(order.id) && (
                                                    <tr>
                                                        <td colSpan={8} css={tw`px-6 py-4 bg-neutral-800`}>
                                                            <OrderPaymentDetails order={order} />
                                                        </td>
                                                    </tr>
                                                )}
                                            </>
                                        ))}
                                </TableBody>
                            </table>
                            {orders === undefined ? <Loading /> : orders.items.length < 1 ? <NoItems /> : null}
                        </div>
                    </Pagination>
                </ContentWrapper>
            </AdminTable>
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
