import { Server } from '@/api/routes/admin/server';
import updateServer, { Values } from '@/api/routes/admin/servers/updateServer';
import { Button } from '@/elements/button';
import { Dialog } from '@/elements/dialog';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import { CashIcon, ClockIcon, PencilAltIcon } from '@heroicons/react/outline';
import classNames from 'classnames';
import { Form, Formik } from 'formik';
import { useState, useEffect } from 'react';
import { getCategories, getCategory } from '@/api/routes/admin/billing/categories';
import { Product } from '@definitions/admin';
import Spinner from '@/elements/Spinner';

export default ({ server }: { server: Server }) => {
    const [open, setOpen] = useState<boolean>(false);
    const [billable, setBillable] = useState<boolean>(Boolean(server.billingProductId));
    const [selectedProductId, setSelectedProductId] = useState<number | null>(server.billingProductId || null);
    const [products, setProducts] = useState<Product[]>([]);
    const [loading, setLoading] = useState<boolean>(false);

    const [renewalDateStr, setRenewalDateStr] = useState<string>(
        server.renewalDate
            ? new Date(server.renewalDate).toISOString().slice(0, 16)
            : new Date().toISOString().slice(0, 16),
    );

    useEffect(() => {
        if (open) {
            setLoading(true);
            getCategories()
                .then(cats => {
                    // Fetch each category with its products
                    const promises = cats.map(cat => getCategory(cat.id));
                    return Promise.all(promises);
                })
                .then(categoriesWithProducts => {
                    // Flatten all products from all categories
                    const allProducts: Product[] = [];
                    categoriesWithProducts.forEach(cat => {
                        if (cat.relationships?.products) {
                            allProducts.push(...cat.relationships.products);
                        }
                    });
                    setProducts(allProducts);
                })
                .catch(err => console.error('Failed to fetch products:', err))
                .finally(() => setLoading(false));
        }
    }, [open]);

    const localStrToUTC = (localStr: string): Date => {
        const localDate = new Date(localStr);
        return new Date(localDate.getTime() - localDate.getTimezoneOffset() * 60000);
    };

    const submit = () => {
        const payload: Partial<Values> = {
            ...(server as unknown as Partial<Values>),
        };

        if (billable) {
            // When enabling billing, set the renewal date and product ID
            if (!renewalDateStr) {
                console.error('No date selected');
                return;
            }
            if (!selectedProductId) {
                console.error('No product selected - please select a billing plan');
                return;
            }
            const utcDate = localStrToUTC(renewalDateStr);
            payload.renewalDate = utcDate;
            payload.billingProductId = selectedProductId;
        } else {
            // When disabling billing, clear the renewal date and product ID to stop billing
            payload.renewalDate = null;
            payload.billingProductId = null;
        }

        updateServer(server.id, payload)
            .then(() => window.location.reload())
            .catch(error => console.log(error.message));
    };

    return (
        <>
            <Dialog open={open} onClose={() => setOpen(false)} title={'Edit Server Billing'}>
                <Formik onSubmit={submit} initialValues={{}}>
                    <Form>
                        <div className={'grid space-y-6'}>
                            <div>
                                <div className={'flex'}>
                                    <Label>
                                        <CashIcon className={'w-4 inline-flex'} /> Billing Status
                                    </Label>
                                    <span className={'ml-2 italic text-gray-400 text-sm'}>
                                        Should this server be billed automatically?
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setBillable(true)}
                                    className={classNames(
                                        billable ? 'bg-black/50' : 'bg-black/25',
                                        'rounded-l py-3 px-6 font-bold text-white',
                                    )}
                                >
                                    Enabled
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setBillable(false)}
                                    className={classNames(
                                        !billable ? 'bg-black/50' : 'bg-black/25',
                                        'rounded-r py-3 px-6 font-bold text-white',
                                    )}
                                >
                                    Disabled
                                </button>
                            </div>

                            {billable && (
                                <div>
                                    <div className={'flex'}>
                                        <Label>
                                            <CashIcon className={'w-4 inline-flex'} /> Billing Plan
                                        </Label>
                                        <span className={'ml-2 italic text-gray-400 text-sm'}>
                                            Select the billing product for this server.
                                        </span>
                                    </div>
                                    {loading ? (
                                        <Spinner size={'small'} />
                                    ) : (
                                        <select
                                            value={selectedProductId || ''}
                                            onChange={e => setSelectedProductId(Number(e.target.value) || null)}
                                            className={
                                                'w-full px-3 py-2 bg-neutral-800 border border-neutral-700 rounded text-sm'
                                            }
                                        >
                                            <option value="">Select a billing plan...</option>
                                            {products.map(product => (
                                                <option key={product.id} value={product.id}>
                                                    {product.name} - ${product.price}/month ({product.limits.cpu}% CPU,{' '}
                                                    {product.limits.memory / 1024}GB RAM, {product.limits.disk / 1024}GB
                                                    Disk)
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                    {!selectedProductId && (
                                        <p className={'text-red-400 text-sm mt-1'}>
                                            Please select a billing plan to enable billing
                                        </p>
                                    )}
                                </div>
                            )}

                            <div>
                                <div className={'flex'}>
                                    <Label>
                                        <ClockIcon className={'w-4 inline-flex'} /> Renewal Date
                                    </Label>
                                    <span className={'ml-2 italic text-gray-400 text-sm'}>
                                        Adjust when this server will renew.
                                    </span>
                                </div>
                                <Input
                                    type="datetime-local"
                                    value={renewalDateStr}
                                    onChange={e => setRenewalDateStr(e.target.value)}
                                />
                                <p>Server will be set to next renew on: {renewalDateStr.split('T')[0]}</p>
                            </div>

                            <div className={'ml-auto'}>
                                <Button type="button" onClick={submit}>
                                    Save Changes
                                </Button>
                            </div>
                        </div>
                    </Form>
                </Formik>
            </Dialog>

            <Button size={Button.Sizes.Small} onClick={() => setOpen(true)}>
                Edit <PencilAltIcon className={'ml-1 w-4'} />
            </Button>
        </>
    );
};
