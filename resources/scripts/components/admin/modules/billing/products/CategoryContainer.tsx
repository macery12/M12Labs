import Spinner from '@/elements/Spinner';
import { Link } from 'react-router-dom';
import { Button } from '@/elements/button';
import AdminContentBlock from '@/elements/AdminContentBlock';
import { useCategoryFromRoute } from '@/api/routes/admin/billing/categories';
import CategoryForm from '@admin/modules/billing/products/CategoryForm';
import ProductTable from '@admin/modules/billing/products/ProductTable';

export default () => {
    const { data } = useCategoryFromRoute();

    if (!data) return <Spinner size={'large'} centered />;

    return (
        <AdminContentBlock title={data.name || 'View Category'}>
            <CategoryForm category={data} />
            <div className={'mt-12 mb-4 h-px w-full rounded-full border-2 border-gray-700'} />
            <div className={'flex w-full flex-row items-center p-8'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Products</h2>
                    <p
                        className={
                            'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                        }
                    >
                        A list of the available products in the {data.name} category.
                    </p>
                </div>
                <div className={'ml-auto flex pl-4'}>
                    <Link to={`/admin/billing/categories/${data.id}/products/new`}>
                        <Button>Create Product</Button>
                    </Link>
                </div>
            </div>
            <ProductTable />
        </AdminContentBlock>
    );
};
