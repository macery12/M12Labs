import Spinner from '@/elements/Spinner';
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useStoreState } from '@/state/hooks';
import NodeBox from '@account/billing/order/NodeBox';
import PageContentBlock from '@/elements/PageContentBlock';
import VariableBox from '@account/billing/order/VariableBox';
import CouponInput from '@account/billing/order/CouponInput';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import {
    faArchive,
    faCreditCard,
    faDatabase,
    faEthernet,
    faExternalLinkAlt,
    faHdd,
    faIdBadge,
    faMemory,
    faMicrochip,
} from '@fortawesome/free-solid-svg-icons';
import { Alert } from '@/elements/alert';
import useFlash from '@/plugins/useFlash';
import PaymentButton from './PaymentButton';
import { Elements } from '@stripe/react-stripe-js';
import { loadStripe, Stripe } from '@stripe/stripe-js';
import { EggVariable } from '@definitions/server';
import { Button } from '@/elements/button';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { Product, StripeIntent, type Node } from '@definitions/account/billing';
import { processUnpaidOrder } from '@/api/routes/account/billing/orders/process';
import { getProduct, getProductVariables, getViableNodes } from '@/api/routes/account/billing/products';
import { getStripeIntent, getStripeKey } from '@/api/routes/account/billing/orders/stripe';
import TitledGreyBox from '@/elements/TitledGreyBox';
import AdminCheckbox from '@/elements/AdminCheckbox';
import { ValidateCouponResponse } from '@/api/routes/account/billing/coupons';

const LimitBox = ({ icon, content }: { icon: IconDefinition; content: string }) => {
    return (
        <div className={'my-1 font-semibold text-gray-400'}>
            <FontAwesomeIcon icon={icon} className={'mr-2 inline-flex h-4 w-4 '} />
            {content}
        </div>
    );
};

export default () => {
    const params = useParams<'id'>();

    const vars = useRef(new Map<string, string>()).current;
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const navigate = useNavigate();

    const billing = useStoreState(state => state.everest.data!.billing);

    const [stripe, setStripe] = useState<Stripe | null>(null);
    const [intent, setIntent] = useState<StripeIntent | null>(null);
    const [nodes, setNodes] = useState<Node[] | undefined>();
    const [selectedNode, setSelectedNode] = useState<number>(0);
    const [product, setProduct] = useState<Product | undefined>();
    const [eggs, setEggs] = useState<EggVariable[] | undefined>();

    const [termsAgreed, setTermsAgreed] = useState<boolean>(false);
    const [privacyAgreed, setPrivacyAgreed] = useState<boolean>(false);
    const [couponData, setCouponData] = useState<ValidateCouponResponse | null>(null);

    const { colors } = useStoreState(state => state.theme.data!);

    const handleCouponApplied = (data: ValidateCouponResponse | null) => {
        setCouponData(data);

        // Only regenerate intent if the final total is not zero
        if (product && product.price !== 0) {
            const finalTotal = data ? data.total : product.price;

            // If coupon makes it free, don't fetch intent
            if (finalTotal === 0) {
                setIntent(null);
            } else {
                // Regenerate intent with new amount for paid products
                getStripeIntent(Number(params.id), data?.coupon.id)
                    .then(intentData => setIntent({ id: intentData.id, secret: intentData.secret }))
                    .catch(error => console.error('Error updating payment intent:', error));
            }
        }
    };

    const createFree = () => {
        if (product) {
            const variables = Array.from(vars, ([key, value]) => ({ key, value }));
            processUnpaidOrder(product.id, selectedNode, undefined, variables, undefined, couponData?.coupon.id)
                .then(() => navigate('/'))
                .catch(error => clearAndAddHttpError({ key: 'account:billing:order', error }));
        }
    };

    useEffect(() => {
        const fetchData = async () => {
            try {
                // Fetch product details
                const productData = await getProduct(Number(params.id));
                setProduct(productData);

                // Fetch nodes
                const nodesData = await getViableNodes(productData.id);
                setNodes(nodesData);
                setSelectedNode(Number(nodesData[0]?.id) ?? 0);

                if (productData.price !== 0) {
                    // Fetch payment intent
                    const intentData = await getStripeIntent(Number(params.id));
                    setIntent({ id: intentData.id, secret: intentData.secret });

                    // Fetch Stripe public key and initialize Stripe
                    const stripePublicKey = await getStripeKey(Number(params.id));
                    const stripeInstance = await loadStripe(stripePublicKey.key);
                    setStripe(stripeInstance);
                }
            } catch (error) {
                console.error('Error fetching data:', error);
            }
        };

        fetchData();
    }, [params.id]);

    useEffect(() => {
        clearFlashes();

        if (!product || eggs) return;

        // Fetch product variables (egg data)
        getProductVariables(Number(product.eggId))
            .then(data => setEggs(data))
            .catch(error => console.error(error));
    }, [product]);

    if (!product) return <Spinner centered />;
    // Only show spinner for paid products when Stripe hasn't loaded yet
    // If a coupon makes it free, we don't need Stripe
    const needsStripe = product.price !== 0 && (!couponData || couponData.total !== 0);
    if (needsStripe && (!intent || !stripe)) return <Spinner centered />;

    const options = {
        clientSecret: intent?.secret,
        appearance: {
            theme: 'night',
            variables: {
                colorText: '#ffffff',
            },
        },
    };

    return (
        <PageContentBlock title={'Your Order'}>
            <FlashMessageRender byKey={'account:billing:order'} className={'mb-4'} />
            <div className={'mt-8 mb-12 text-3xl font-bold lg:text-5xl'}>
                Your Order
                <p className={'mt-1 text-sm font-normal text-gray-400'}>
                    Customize your selected plan and submit a payment.
                </p>
            </div>
            <div className={'grid gap-4 lg:grid-cols-8 lg:gap-12'}>
                <div className={'border-gray-500 lg:col-span-2 lg:border-r-4'}>
                    <p className={'my-4 text-2xl font-bold text-gray-300'}>
                        Selected Plan
                        {product.icon && <img src={product.icon} className={'ml-2 inline-flex h-8 w-8'} />}
                    </p>
                    <LimitBox icon={faIdBadge} content={product.name} />
                    <div className={'my-1 text-lg font-semibold text-gray-400'}>
                        <FontAwesomeIcon icon={faCreditCard} className={'mr-2 inline-flex h-4 w-4 '} />
                        {couponData ? (
                            <div>
                                <div className={'text-sm line-through'}>${couponData.subtotal}</div>
                                <div>
                                    <span style={{ color: colors.primary }} className={'mr-1'}>
                                        ${couponData.total.toFixed(2)}
                                    </span>
                                    <span className={'text-sm'}>/ mo</span>
                                </div>
                                <div className={'text-xs text-green-500'}>Save ${couponData.discount.toFixed(2)}</div>
                            </div>
                        ) : (
                            <>
                                <span style={{ color: colors.primary }} className={'mr-1'}>
                                    ${product.price}
                                </span>
                                <span className={'text-sm'}>/ mo</span>
                            </>
                        )}
                    </div>
                    <div className={'my-4 mr-8 h-0.5 rounded-full bg-gray-600'} />
                    <LimitBox icon={faMicrochip} content={`${product.limits.cpu}% CPU`} />
                    <LimitBox icon={faMemory} content={`${(product.limits.memory / 1024).toFixed(1)} GiB Memory`} />
                    <LimitBox icon={faHdd} content={`${(product.limits.disk / 1024).toFixed(1)} GiB Disk`} />
                    <div className={'my-4 mr-8 h-0.5 rounded-full bg-gray-600'} />
                    <LimitBox icon={faArchive} content={`${product.limits.backup} Backup Slots`} />
                    <LimitBox icon={faDatabase} content={`${product.limits.database} Database Slots`} />
                    <LimitBox icon={faEthernet} content={`${product.limits.allocation} Network Ports`} />
                </div>
                <div className={'lg:col-span-6'}>
                    <div>
                        <div className={'my-10'}>
                            <div className={'mb-4 text-xl font-semibold lg:text-3xl'}>
                                Choose a location
                                <p className={'mt-1 text-sm font-normal text-gray-400'}>
                                    Select a location from our list to deploy your server to.
                                </p>
                            </div>
                            <div className={'grid gap-4 lg:grid-cols-2'}>
                                {(!nodes || nodes.length < 1) && (
                                    <Alert type={'danger'} className={'col-span-2'}>
                                        There are no nodes available for deployment. Please contact an administrator.
                                    </Alert>
                                )}
                                {nodes?.map(node => (
                                    <NodeBox
                                        node={node}
                                        key={node.id}
                                        selected={selectedNode}
                                        setSelected={setSelectedNode}
                                    />
                                ))}
                            </div>
                        </div>
                        <div className={'h-px rounded-full bg-gray-700'} />
                        {eggs && eggs.length > 1 && (
                            <>
                                <div className={'my-10'}>
                                    <div className={'mb-4 text-xl font-semibold lg:text-3xl'}>
                                        Plan Variables
                                        <p className={'mt-1 text-sm font-normal text-gray-400'}>
                                            Modify your server variables before your server is even created for ease of
                                            use.
                                        </p>
                                    </div>
                                    <div className={'grid gap-4 lg:grid-cols-2'}>
                                        {eggs?.map(variable => (
                                            <div key={variable.envVariable}>
                                                {variable.isEditable && <VariableBox variable={variable} vars={vars} />}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                <div className={'h-px rounded-full bg-gray-700'} />
                            </>
                        )}
                        <div className={'my-10'}>
                            <div className={'mb-4 text-xl font-semibold lg:text-3xl'}>
                                Legal Documents
                                <p className={'mt-1 text-sm font-normal text-gray-400'}>
                                    Agree and sign the relevant legal documents for your new server.
                                </p>
                            </div>
                            <div className={'grid gap-4 lg:grid-cols-2'}>
                                <TitledGreyBox title={'Terms of Service agreement'} className={'relative'}>
                                    {!termsAgreed ? (
                                        <>
                                            Click the checkbox to agree to our{' '}
                                            <a href={billing.links.terms} className={'font-semibold text-blue-400'}>
                                                Terms of Service <FontAwesomeIcon icon={faExternalLinkAlt} />
                                            </a>
                                        </>
                                    ) : (
                                        <Alert type={'success'}>Terms of Service completed</Alert>
                                    )}
                                    {!termsAgreed && (
                                        <div className={'absolute top-0 right-0 p-3'}>
                                            <AdminCheckbox
                                                name={'terms'}
                                                checked={false}
                                                onChange={() => setTermsAgreed(true)}
                                            />
                                        </div>
                                    )}
                                </TitledGreyBox>
                                <TitledGreyBox title={'Privacy Policy agreement'} className={'relative'}>
                                    {!privacyAgreed ? (
                                        <>
                                            Click the checkbox to agree to our{' '}
                                            <a href={billing.links.privacy} className={'font-semibold text-blue-400'}>
                                                Privacy Policy <FontAwesomeIcon icon={faExternalLinkAlt} />
                                            </a>
                                        </>
                                    ) : (
                                        <Alert type={'success'}>Privacy Policy completed</Alert>
                                    )}
                                    {!privacyAgreed && (
                                        <div className={'absolute top-0 right-0 p-3'}>
                                            <AdminCheckbox
                                                name={'privacy'}
                                                checked={false}
                                                onChange={() => setPrivacyAgreed(true)}
                                            />
                                        </div>
                                    )}
                                </TitledGreyBox>
                            </div>
                        </div>
                        <div className={'h-px rounded-full bg-gray-700'} />
                        {product.price !== 0 && (
                            <>
                                <div className={'my-10'}>
                                    <div className={'mb-4 text-xl font-semibold lg:text-3xl'}>
                                        Coupon Code
                                        <p className={'mt-1 text-sm font-normal text-gray-400'}>
                                            Have a coupon? Apply it here to get a discount on your order.
                                        </p>
                                    </div>
                                    <CouponInput subtotal={product.price} onCouponApplied={handleCouponApplied} />
                                    <FlashMessageRender byKey={'coupon'} className={'mt-4'} />
                                </div>
                                <div className={'h-px rounded-full bg-gray-700'} />
                            </>
                        )}
                        {!termsAgreed || !privacyAgreed ? (
                            <Alert type={'warning'}>
                                Please agree to the above legal documents before proceeding with your order.
                            </Alert>
                        ) : (
                            <>
                                {product.price === 0 || couponData?.total === 0 ? (
                                    <div className={'mt-8 flex w-full'}>
                                        <p className={'font-semibold text-gray-400'}>
                                            {couponData?.total === 0
                                                ? 'Your coupon has made this order free! No payment is required.'
                                                : 'As this product is free, no purchase needs to be made via our payment gateways.'}
                                        </p>
                                        <Button className={'ml-auto'} onClick={createFree}>
                                            Create Server
                                        </Button>
                                    </div>
                                ) : intent ? (
                                    <div className={'mt-8 w-full'}>
                                        {/* @ts-expect-error this is fine, stripe library is just weird */}
                                        <Elements stripe={stripe} options={options} key={intent?.id}>
                                            <PaymentButton
                                                selectedNode={selectedNode}
                                                product={product}
                                                vars={vars}
                                                intent={intent}
                                                couponId={couponData?.coupon.id}
                                            />
                                        </Elements>
                                    </div>
                                ) : (
                                    <Spinner centered />
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>
        </PageContentBlock>
    );
};
