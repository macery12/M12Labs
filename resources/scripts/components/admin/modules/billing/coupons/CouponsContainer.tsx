import { useGetCoupons, Context as CouponContext } from '@/api/routes/admin/billing/coupons';
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
import { format } from 'date-fns';
import { Link } from 'react-router-dom';
import tw from 'twin.macro';
import { useStoreState } from '@/state/hooks';
import { useContext, useEffect } from 'react';
import { Button } from '@/elements/button';
import { TicketIcon } from '@heroicons/react/outline';
import useFlash from '@/plugins/useFlash';
import { CouponFilters } from '@/api/routes/admin/billing/types';

function CouponsContainer() {
    const { data: coupons, error } = useGetCoupons();
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { colors } = useStoreState(state => state.theme.data!);
    const { setPage, setFilters, sort, setSort, sortDirection, filters } = useContext(CouponContext);

    const onSearch = (query: string): Promise<void> => {
        return new Promise(resolve => {
            if (query.length < 2) {
                setFilters(null);
            } else {
                setFilters({ code: query });
            }
            return resolve();
        });
    };

    useEffect(() => {
        if (!error) {
            clearFlashes('admin:billing:coupons');
            return;
        }

        clearAndAddHttpError({ key: 'admin:billing:coupons', error });
    }, [error]);

    return (
        <>
            <div className={'w-full flex flex-row items-center my-8 px-8'}>
                <div className={'flex flex-col flex-shrink'} style={{ minWidth: '0' }}>
                    <h2 className={'text-2xl text-neutral-50 font-header font-medium'}>Coupons</h2>
                    <p
                        className={
                            'hidden lg:block text-base text-neutral-400 whitespace-nowrap overflow-ellipsis overflow-hidden'
                        }
                    >
                        Manage discount coupons for the billing system.
                    </p>
                </div>
                <div className={'flex ml-auto pl-4'}>
                    <Link to={'/admin/billing/coupons/new'}>
                        <Button>Add Coupon</Button>
                    </Link>
                </div>
            </div>
            <AdminTable>
                <ContentWrapper onSearch={onSearch}>
                    <Pagination data={coupons} onPageSelect={setPage}>
                        <div css={tw`overflow-x-auto`}>
                            <table css={tw`w-full table-auto`}>
                                <TableHead>
                                    <TableHeader />
                                    <TableHeader
                                        name={'ID'}
                                        direction={sort === 'id' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('id')}
                                    />
                                    <TableHeader
                                        name={'Code'}
                                        direction={sort === 'code' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('code')}
                                    />
                                    <TableHeader
                                        name={'Type'}
                                        direction={sort === 'type' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('type')}
                                    />
                                    <TableHeader
                                        name={'Value'}
                                        direction={sort === 'value' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('value')}
                                    />
                                    <TableHeader
                                        name={'Usage'}
                                        direction={sort === 'usage_count' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('usage_count')}
                                    />
                                    <TableHeader
                                        name={'Status'}
                                        direction={sort === 'is_active' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('is_active')}
                                    />
                                    <TableHeader
                                        name={'Expires At'}
                                        direction={sort === 'expires_at' ? (sortDirection ? 1 : 2) : null}
                                        onClick={() => setSort('expires_at')}
                                    />
                                </TableHead>
                                <TableBody>
                                    {coupons !== undefined &&
                                        !coupons.items.length &&
                                        ((!filters || Object.keys(filters).length === 0 ? (
                                            <tr>
                                                <td colSpan={8}>
                                                    <NoItems />
                                                </td>
                                            </tr>
                                        ) : (
                                            <tr>
                                                <td colSpan={8}>
                                                    <div css={tw`text-center text-sm text-neutral-400`}>
                                                        No coupons found matching your search.
                                                    </div>
                                                </td>
                                            </tr>
                                        )) as any)}
                                    {coupons === undefined ? (
                                        <Loading />
                                    ) : (
                                        coupons.items.map(coupon => (
                                            <TableRow key={coupon.id}>
                                                <td css={tw`whitespace-nowrap`}>
                                                    <Link to={`/admin/billing/coupons/${coupon.id}`}>
                                                        <TicketIcon
                                                            color={colors.primary}
                                                            css={tw`h-5 w-5 cursor-pointer`}
                                                        />
                                                    </Link>
                                                </td>
                                                <td css={tw`pl-4 md:pl-0 text-left lg:text-center`}>
                                                    <Link to={`/admin/billing/coupons/${coupon.id}`}>
                                                        <CopyOnClick text={coupon.id.toString()}>
                                                            <code css={tw`font-mono bg-neutral-900 rounded py-1 px-2`}>
                                                                {coupon.id}
                                                            </code>
                                                        </CopyOnClick>
                                                    </Link>
                                                </td>
                                                <td css={tw`pl-4 md:pl-0 text-left lg:text-center`}>
                                                    <CopyOnClick text={coupon.code}>
                                                        <code css={tw`font-bold cursor-pointer`}>{coupon.code}</code>
                                                    </CopyOnClick>
                                                </td>
                                                <td css={tw`pl-4 md:pl-0 text-left lg:text-center capitalize`}>
                                                    {coupon.type}
                                                </td>
                                                <td css={tw`pl-4 md:pl-0 text-left lg:text-center`}>
                                                    {coupon.type === 'percentage'
                                                        ? `${coupon.value}%`
                                                        : `$${coupon.value}`}
                                                </td>
                                                <td css={tw`pl-4 md:pl-0 text-left lg:text-center`}>
                                                    {coupon.usageCount}
                                                    {coupon.maxUses ? ` / ${coupon.maxUses}` : ''}
                                                </td>
                                                <td css={tw`pl-4 md:pl-0 text-left lg:text-center`}>
                                                    <span
                                                        className={coupon.isActive ? 'text-green-500' : 'text-red-500'}
                                                    >
                                                        {coupon.isActive ? 'Active' : 'Inactive'}
                                                    </span>
                                                </td>
                                                <td css={tw`pl-4 md:pl-0 text-left lg:text-center`}>
                                                    {coupon.expiresAt
                                                        ? format(coupon.expiresAt, 'MMM dd, yyyy')
                                                        : 'Never'}
                                                </td>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </table>
                        </div>
                    </Pagination>
                </ContentWrapper>
            </AdminTable>
        </>
    );
}

export default () => {
    const hooks = useTableHooks<CouponFilters>();

    return (
        <CouponContext.Provider value={hooks}>
            <CouponsContainer />
        </CouponContext.Provider>
    );
};
