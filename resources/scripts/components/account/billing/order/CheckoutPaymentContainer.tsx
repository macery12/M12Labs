import Spinner from '@/elements/Spinner';
import PageContentBlock from '@/elements/PageContentBlock';
import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { useStoreState } from '@/state/hooks';
import { Alert } from '@/elements/alert';
import { Button } from '@/elements/button';
import SubtotalCard from '@account/billing/order/SubtotalCard';
import PaymentMethodSelector from './PaymentMethodSelector';
import FlashMessageRender from '@/elements/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import {
    getProduct,
    getProductBillingCycles,
    getViableNodes,
    getEggInfo,
    type EggInfo,
    type BillingCycle,
} from '@/api/routes/account/billing/products';
import { Product, StripeIntent, type Node } from '@definitions/account/billing';
import { processUnpaidOrder } from '@/api/routes/account/billing/orders/process';
import { getStripeIntent, getStripeKey } from '@/api/routes/account/billing/orders/stripe';
import { loadStripeOnce } from '@/lib/stripe';
import { Stripe } from '@stripe/stripe-js';
import { ValidateCouponResponse } from '@/api/routes/account/billing/coupons';

interface CheckoutState {
    productId: number;
    selectedNode: number;
    selectedBillingDays: number;
    selectedEggId?: number;
    vars?: [string, string][];
    couponId?: number;
    couponData?: ValidateCouponResponse | null;
    serverName: string;
}

export default () => {
    const { state } = useLocation();
    const navigate = useNavigate();
    const billing = useStoreState(s => s.everest.data!.billing);
    const { addFlash, clearAndAddHttpError, clearFlashes } = useFlash();
    const { colors } = useStoreState(s => s.theme.data!);

    const checkoutState = state as CheckoutState | undefined;

    const [product, setProduct] = useState<Product | undefined>();
    const [billingCycles, setBillingCycles] = useState<BillingCycle[]>([]);
    const [nodes, setNodes] = useState<Node[]>([]);
    const [selectedEgg, setSelectedEgg] = useState<EggInfo | undefined>();
    const [stripe, setStripe] = useState<Stripe | null>(null);
    const [intent, setIntent] = useState<StripeIntent | null>(null);
    const [loading, setLoading] = useState<boolean>(false);

    const vars = useMemo(
        () => new Map<string, string>(checkoutState?.vars ? [...checkoutState.vars] : []),
        [checkoutState?.vars],
    );

    useEffect(() => {
        if (!checkoutState?.productId) {
            addFlash({
                key: 'account:billing:order',
                type: 'warning',
                message: 'Missing configuration details. Please configure your server again.',
            });
            navigate('/account/billing/order', { replace: true });
            return;
        }

        const loadData = async () => {
            try {
                const [productData, cyclesData, nodesData] = await Promise.all([
                    getProduct(checkoutState.productId),
                    getProductBillingCycles(checkoutState.productId),
                    getViableNodes(checkoutState.productId),
                ]);
                setProduct(productData);
                setBillingCycles(cyclesData);
                setNodes(nodesData);

                if (checkoutState.selectedEggId) {
                    const eggInfo = await getEggInfo(checkoutState.selectedEggId);
                    setSelectedEgg(eggInfo);
                }
            } catch (error) {
                clearAndAddHttpError({ key: 'account:billing:order', error });
            }
        };

        loadData();
    }, [checkoutState?.productId]);

    useEffect(() => {
        if (!product || product.price === 0) return;
        if (!billing.processors?.stripe?.available) return;

        // Skip intent creation if coupon makes order free
        if (checkoutState?.couponData?.total === 0) {
            setIntent(null);
            setStripe(null);
            return;
        }

        const initializeStripe = async () => {
            try {
                const intentData = await getStripeIntent(product.id, checkoutState?.couponId);
                setIntent({ id: intentData.id, secret: intentData.secret });

                const stripePublicKey = await getStripeKey(product.id);
                const stripeInstance = await loadStripeOnce(stripePublicKey.key);
                setStripe(stripeInstance);
            } catch (error) {
                console.error('Error initializing Stripe:', error);
            }
        };

        initializeStripe();
    }, [product?.id, checkoutState?.couponId]);

    if (!checkoutState?.productId) {
        return (
            <PageContentBlock title={'Payment'}>
                <FlashMessageRender byKey={'account:billing:order'} className={'mb-4'} />
                <Alert type={'warning'}>Missing checkout details. Please start your configuration again.</Alert>
            </PageContentBlock>
        );
    }

    if (!product) return <Spinner centered />;

    const createFree = async () => {
        clearFlashes();
        setLoading(true);

        try {
            const variables = Array.from(vars, ([key, value]) => ({ key, value }));
            await processUnpaidOrder(
                product.id,
                checkoutState.selectedNode,
                undefined,
                variables,
                undefined,
                checkoutState.couponId,
                checkoutState.selectedEggId,
                checkoutState.serverName,
            );
            navigate('/account/billing/success');
        } catch (error) {
            setLoading(false);
            clearAndAddHttpError({ key: 'account:billing:order', error });
        }
    };

    const totalIsFree = product.price === 0 || checkoutState.couponData?.total === 0;

    const summaryCard = (
        <div
            className={'rounded-lg border p-6 space-y-3'}
            style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}
        >
            <h3 className={'text-lg font-semibold text-gray-200'}>Order Summary</h3>
            <div className={'grid grid-cols-2 gap-3 text-sm'}>
                <div>
                    <p className={'text-xs uppercase text-gray-500'}>Server</p>
                    <p className={'font-semibold text-gray-100'}>{product.name}</p>
                </div>
                <div>
                    <p className={'text-xs uppercase text-gray-500'}>Server Name</p>
                    <p className={'font-semibold text-gray-100'}>{checkoutState.serverName}</p>
                </div>
                <div>
                    <p className={'text-xs uppercase text-gray-500'}>Location</p>
                    <p className={'font-semibold text-gray-100'}>
                        {nodes.find(n => Number(n.id) === checkoutState.selectedNode)?.name || 'Not selected'}
                    </p>
                </div>
                <div>
                    <p className={'text-xs uppercase text-gray-500'}>Software</p>
                    <p className={'font-semibold text-gray-100'}>
                        {selectedEgg?.name || checkoutState.selectedEggId ? 'Selected' : 'Not selected'}
                    </p>
                </div>
                <div>
                    <p className={'text-xs uppercase text-gray-500'}>Billing Cycle</p>
                    <p className={'font-semibold text-gray-100'}>
                        {billingCycles.find(c => c.days === checkoutState.selectedBillingDays)?.label ??
                            `${checkoutState.selectedBillingDays} days`}
                    </p>
                </div>
            </div>
        </div>
    );

    return (
        <PageContentBlock title={'Payment'}>
            <FlashMessageRender byKey={'account:billing:order'} className={'mb-4'} />
            <div className={'mb-8'}>
                <h1 className={'text-4xl font-bold text-gray-100'}>Payment</h1>
                <p className={'mt-2 text-base text-gray-400'}>
                    Review your configuration and select a payment method to complete your order.
                </p>
            </div>

            <div className={'grid gap-8 lg:grid-cols-12'}>
                <div className={'space-y-6 lg:col-span-8'}>
                    {summaryCard}

                    <div
                        className={'rounded-lg border p-6'}
                        style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}
                    >
                        <h3 className={'mb-4 text-lg font-semibold text-gray-200'}>Payment Method</h3>
                        {totalIsFree ? (
                            <div className={'space-y-4'}>
                                <Alert type={'success'}>
                                    This order is free based on your selections{checkoutState.couponId ? ' or coupon' : ''}.
                                </Alert>
                                <Button
                                    size={Button.Sizes.Large}
                                    className={'w-full'}
                                    disabled={loading}
                                    onClick={() => void createFree()}
                                >
                                    {loading ? 'Creating...' : 'Create Server'}
                                </Button>
                            </div>
                        ) : (
                            <PaymentMethodSelector
                                selectedNode={checkoutState.selectedNode}
                                product={product}
                                vars={vars}
                                intent={intent}
                                stripe={stripe}
                                couponId={checkoutState.couponId}
                                selectedEggId={checkoutState.selectedEggId}
                                serverName={checkoutState.serverName}
                            />
                        )}
                    </div>
                </div>

                <div className={'lg:col-span-4'}>
                    <div className={'sticky top-24'}>
                        <SubtotalCard
                            basePrice={product.price}
                            selectedNode={checkoutState.selectedNode}
                            nodes={nodes}
                            selectedEggId={checkoutState.selectedEggId}
                            availableEggs={selectedEgg ? [selectedEgg] : []}
                            selectedBillingDays={checkoutState.selectedBillingDays}
                            billingCycles={billingCycles}
                            couponDiscount={checkoutState.couponData?.discount || 0}
                            couponCode={checkoutState.couponData?.coupon.code}
                            productName={product.name}
                            showDetailedBreakdown={true}
                            showCouponInput={false}
                        />
                    </div>
                </div>
            </div>
        </PageContentBlock>
    );
};
