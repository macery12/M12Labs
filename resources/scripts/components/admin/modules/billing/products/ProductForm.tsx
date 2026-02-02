import type { Actions } from 'easy-peasy';
import { useStoreActions } from 'easy-peasy';
import type { FormikHelpers } from 'formik';
import { Form, Formik } from 'formik';
import { Link, useNavigate, useParams } from 'react-router-dom';
import Field, { FieldRow } from '@/elements/Field';
import tw from 'twin.macro';
import AdminContentBlock from '@/elements/AdminContentBlock';
import { Button } from '@/elements/button';
import type { ApplicationStore } from '@/state';
import AdminBox from '@/elements/AdminBox';
import { object, string, number } from 'yup';
import { faArrowLeft, faBell, faMicrochip, faPuzzlePiece, faCalendarAlt, faDollarSign } from '@fortawesome/free-solid-svg-icons';
import { useStoreState } from '@/state/hooks';
import { createProduct, updateProduct } from '@/api/routes/admin/billing/products';
import ProductDeleteButton from './ProductDeleteButton';
import BillingCyclesManager from './BillingCyclesManager';
import { CubeIcon } from '@heroicons/react/outline';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useState } from 'react';
import { getCategory } from '@/api/routes/admin/billing/categories';
import { Product } from '@definitions/admin';
import { ProductValues } from '@/api/routes/admin/billing/types';
import { Alert } from '@/elements/alert';
import { getBillingCycles, syncBillingCycles, getMultiplierRanges } from '@/api/routes/admin/billing/billingCycles';

export default ({ product }: { product?: Product }) => {
    const navigate = useNavigate();
    const params = useParams<'id'>();
    const [uuid, setUuid] = useState<string>();
    const [billingCycles, setBillingCycles] = useState<Array<{ id?: number; days: number; isEnabled: boolean }>>([]);
    const [multiplierRanges, setMultiplierRanges] = useState<any>(null);

    const { clearFlashes, clearAndAddHttpError } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes,
    );
    const { secondary } = useStoreState(state => state.theme.data!.colors);

    useEffect(() => {
        getCategory(Number(params.id)).then(category => setUuid(category.uuid));
        getMultiplierRanges().then(setMultiplierRanges);
        
        if (product) {
            getBillingCycles(Number(params.id), product.id).then(cycles => {
                setBillingCycles(cycles.map(c => ({ id: c.id, days: c.days, isEnabled: c.isEnabled ?? true })));
            });
        }
    }, [params.id, product?.id]);

    const submit = (values: ProductValues, { setSubmitting }: FormikHelpers<ProductValues>) => {
        clearFlashes('admin:billing:product:create');

        const submitData = {
            ...values,
            base_price: values.basePrice,
            multiplier_up: values.multiplierUp,
            multiplier_down: values.multiplierDown,
        };

        if (!product) {
            createProduct(Number(params.id), submitData)
                .then(data => {
                    setSubmitting(false);
                    
                    if (billingCycles.length > 0) {
                        syncBillingCycles(
                            Number(params.id),
                            data.id,
                            billingCycles.map(c => ({ days: c.days, is_enabled: c.isEnabled })),
                        ).then(() => {
                            navigate(`/admin/billing/categories/${params.id}/products/${data.id}`);
                        }).catch(error => {
                            clearAndAddHttpError({ key: 'admin:billing:product:create', error });
                        });
                    } else {
                        navigate(`/admin/billing/categories/${params.id}/products/${data.id}`);
                    }
                })
                .catch(error => {
                    setSubmitting(false);
                    clearAndAddHttpError({ key: 'admin:billing:product:create', error });
                });
        } else {
            updateProduct(Number(params.id), product!.id, submitData)
                .then(() => {
                    if (billingCycles.length > 0) {
                        return syncBillingCycles(
                            Number(params.id),
                            product.id,
                            billingCycles.map(c => ({ days: c.days, is_enabled: c.isEnabled })),
                        );
                    }
                })
                .then(() => {
                    setSubmitting(false);
                    navigate(`/admin/billing/categories/${params.id}`);
                })
                .catch(error => {
                    setSubmitting(false);
                    clearAndAddHttpError({ key: 'admin:billing:product:create', error });
                });
        }
    };

    return (
        <>
            <div css={tw`w-full flex flex-row items-center m-8`}>
                {product?.icon ? (
                    <img src={product.icon} className={'ww-8 mr-4 h-8'} />
                ) : (
                    <CubeIcon className={'mr-4 h-8 w-8'} />
                )}
                <div css={tw`flex flex-col flex-shrink`} style={{ minWidth: '0' }}>
                    <h2 css={tw`text-2xl text-neutral-50 font-header font-medium`}>{product?.name ?? 'New Product'}</h2>
                    <p
                        css={tw`hidden lg:block text-base text-neutral-400 whitespace-nowrap overflow-ellipsis overflow-hidden`}
                    >
                        {product?.uuid ?? 'Add a new product to the billing interface.'}
                    </p>
                </div>
                {product && (
                    <div className={'ml-auto mr-12 hidden md:flex'}>
                        <Link to={`/admin/billing/categories/${Number(params.id)}`}>
                            <Button>
                                <FontAwesomeIcon icon={faArrowLeft} className={'mr-2'} />
                                Return to Category
                            </Button>
                        </Link>
                    </div>
                )}
            </div>
            <Formik
                onSubmit={submit}
                initialValues={{
                    categoryUuid: uuid!,
                    name: product?.name ?? 'Plan Name',
                    icon: product?.icon ?? undefined,
                    // @ts-expect-error this is fine
                    price: product?.price?.toString() ?? '9.99',
                    // @ts-expect-error this is fine
                    basePrice: product?.basePrice?.toString() ?? undefined,
                    multiplierUp: product?.multiplierUp ?? 1.0,
                    multiplierDown: product?.multiplierDown ?? 1.0,
                    description: product?.description ?? 'This is a server plan.',
                    limits: {
                        cpu: product?.limits.cpu ?? 100,
                        memory: product?.limits.memory ?? 1024,
                        disk: product?.limits.disk ?? 4096,
                        backup: product?.limits.backup ?? 0,
                        database: product?.limits.database ?? 0,
                        allocation: product?.limits.allocation ?? 1,
                    },
                }}
                validationSchema={object().shape({
                    name: string().required().max(191).min(3),
                    icon: string().nullable().max(191).min(3),
                    price: number().typeError('Price must be a number').required().min(0, 'Price cannot be negative'),
                    basePrice: number().nullable().typeError('Base price must be a number').min(0, 'Base price cannot be negative'),
                    multiplierUp: number().typeError('Multiplier must be a number').min(0.5).max(1.0),
                    multiplierDown: number().typeError('Multiplier must be a number').min(1.0).max(2.0),
                    description: string().nullable().max(191).min(3),
                    limits: object().shape({
                        cpu: number().required().min(10),
                        memory: number().required().min(128),
                        disk: number().required().min(128),
                        backup: number().required().min(0),
                        database: number().required().min(0),
                        allocation: number().required().min(1),
                    }),
                })}
            >
                {({ values, isSubmitting, isValid, handleChange }) => (
                    <Form>
                        <div css={tw`grid grid-cols-1 lg:grid-cols-2 gap-4`}>
                            <div css={tw`w-full flex flex-col mr-0 lg:mr-2`}>
                                <AdminBox title={'General Details'} icon={faPuzzlePiece}>
                                    <FieldRow>
                                        <Field
                                            id={'name'}
                                            name={'name'}
                                            type={'text'}
                                            label={'Name'}
                                            description={'A simple name to identify this product.'}
                                        />
                                        <Field
                                            id={'description'}
                                            name={'description'}
                                            type={'text'}
                                            label={'Description'}
                                            description={'A tagline or description for this product.'}
                                        />
                                        <Field
                                            id={'icon'}
                                            name={'icon'}
                                            type={'text'}
                                            label={'Icon'}
                                            description={'An icon to be displayed with this product.'}
                                        />
                                    </FieldRow>
                                </AdminBox>
                                
                                <AdminBox title={'Pricing Configuration'} className={'lg:mt-4'} icon={faDollarSign}>
                                    <FieldRow>
                                        <Field
                                            id={'price'}
                                            name={'price'}
                                            type={'text'}
                                            onChange={handleChange}
                                            label={'Legacy Monthly Cost'}
                                            description={
                                                'The legacy monthly price (kept for backward compatibility). Use Base Price for new pricing.'
                                            }
                                        />
                                        <Field
                                            id={'basePrice'}
                                            name={'basePrice'}
                                            type={'text'}
                                            onChange={handleChange}
                                            label={'Base Price (30 days)'}
                                            description={
                                                'The base price for 30 days. Leave empty to use legacy price.'
                                            }
                                        />
                                        <Field
                                            id={'multiplierUp'}
                                            name={'multiplierUp'}
                                            type={'text'}
                                            onChange={handleChange}
                                            label={'Multiplier Up (>30 days)'}
                                            description={
                                                multiplierRanges
                                                    ? `${multiplierRanges.multiplier_up.description} (Suggested: ${multiplierRanges.multiplier_up.suggested})`
                                                    : 'Discount for longer billing cycles (e.g., 0.85 = 15% discount)'
                                            }
                                        />
                                        <Field
                                            id={'multiplierDown'}
                                            name={'multiplierDown'}
                                            type={'text'}
                                            onChange={handleChange}
                                            label={'Multiplier Down (<30 days)'}
                                            description={
                                                multiplierRanges
                                                    ? `${multiplierRanges.multiplier_down.description} (Suggested: ${multiplierRanges.multiplier_down.suggested})`
                                                    : 'Premium for shorter billing cycles (e.g., 1.25 = 25% premium)'
                                            }
                                        />
                                    </FieldRow>
                                </AdminBox>
                                
                                <AdminBox title={'Resource Limits'} className={'lg:mt-4'} icon={faMicrochip}>
                                    <FieldRow>
                                        <Field
                                            id={'limits.cpu'}
                                            name={'limits.cpu'}
                                            type={'text'}
                                            label={'CPU Limit (%)'}
                                            description={'The amount of a CPU thread a server can use.'}
                                        />
                                        <Field
                                            id={'limits.memory'}
                                            name={'limits.memory'}
                                            type={'text'}
                                            label={'Memory Limit (MB)'}
                                            description={'The amount of memory a server is allowed to use.'}
                                        />
                                        <Field
                                            id={'limits.disk'}
                                            name={'limits.disk'}
                                            type={'text'}
                                            label={'Disk Limit (MB)'}
                                            description={'The amount of disk a server is allowed to use.'}
                                        />
                                    </FieldRow>
                                </AdminBox>
                            </div>
                            <div css={tw`w-full flex flex-col mr-0 lg:mr-2`}>
                                <AdminBox title={'Feature Limits'} icon={faBell}>
                                    <FieldRow>
                                        <Field
                                            id={'limits.backup'}
                                            name={'limits.backup'}
                                            type={'text'}
                                            label={'Backup Limit'}
                                            description={'The amount of backups this product can have.'}
                                        />
                                        <Field
                                            id={'limits.database'}
                                            name={'limits.database'}
                                            type={'text'}
                                            label={'Database Limit'}
                                            description={'The amount of databases this product can have.'}
                                        />
                                        <Field
                                            id={'limits.allocation'}
                                            name={'limits.allocation'}
                                            type={'text'}
                                            label={'Allocation (Port) Limit'}
                                            description={'The amount of ports this product can have.'}
                                        />
                                    </FieldRow>
                                </AdminBox>
                                
                                <AdminBox title={'Billing Cycles'} className={'lg:mt-4'} icon={faCalendarAlt}>
                                    <BillingCyclesManager
                                        cycles={billingCycles}
                                        basePrice={Number(values.basePrice || values.price)}
                                        multiplierUp={Number(values.multiplierUp)}
                                        multiplierDown={Number(values.multiplierDown)}
                                        onChange={setBillingCycles}
                                    />
                                </AdminBox>
                                
                                {/* Dynamic alerts based on price */}
                                {Number(values.price) === 0 && (
                                    <Alert type={'warning'} className={'mt-4'}>
                                        You have set this product to be free. Please confirm this choice before
                                        proceeding, otherwise users will be able to use this plan without payment.
                                    </Alert>
                                )}
                                {Number(values.price) === 0 && (
                                    <Alert type={'info'} className={'mt-4'}>
                                        As this product is free, users will only be able to use it once to prevent
                                        abuse.
                                    </Alert>
                                )}
                                <div css={tw`rounded shadow-md mt-4 py-2 pr-6`} style={{ backgroundColor: secondary }}>
                                    <div css={tw`text-right`}>
                                        {product && <ProductDeleteButton product={product} />}
                                        <Button type={'submit'} disabled={isSubmitting || !isValid}>
                                            {product ? 'Update Product' : 'Create Product'}
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Form>
                )}
            </Formik>
        </>
    );
};
