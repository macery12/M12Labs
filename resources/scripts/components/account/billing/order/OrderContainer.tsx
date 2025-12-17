import Spinner from '@/elements/Spinner';
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useStoreState } from '@/state/hooks';
import NodeBox from '@account/billing/order/NodeBox';
import PageContentBlock from '@/elements/PageContentBlock';
import VariableBox from '@account/billing/order/VariableBox';
import CouponInput from '@account/billing/order/CouponInput';
import CheckoutStepper from '@account/billing/order/CheckoutStepper';
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
import { getProduct, getProductVariables, getViableNodes, getEggInfo, type EggInfo } from '@/api/routes/account/billing/products';
import { getStripeIntent, getStripeKey } from '@/api/routes/account/billing/orders/stripe';
import TitledGreyBox from '@/elements/TitledGreyBox';
import AdminCheckbox from '@/elements/AdminCheckbox';
import { ValidateCouponResponse } from '@/api/routes/account/billing/coupons';
import Select from '@/elements/Select';
import classNames from 'classnames';

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
    const [selectedEggId, setSelectedEggId] = useState<number | undefined>();
    const [availableEggs, setAvailableEggs] = useState<EggInfo[]>([]);

    const [termsAgreed, setTermsAgreed] = useState<boolean>(false);
    const [privacyAgreed, setPrivacyAgreed] = useState<boolean>(false);
    const [couponData, setCouponData] = useState<ValidateCouponResponse | null>(null);

    const { colors } = useStoreState(state => state.theme.data!);

    // Calculate checkout steps progress
    const getCheckoutSteps = () => {
        const steps = [
            { id: 1, name: 'Location', status: selectedNode ? 'complete' : 'current' },
            { id: 2, name: 'Configuration', status: selectedNode ? (availableEggs.length > 1 && selectedEggId ? 'complete' : 'current') : 'upcoming' },
            { id: 3, name: 'Legal', status: termsAgreed && privacyAgreed ? 'complete' : (selectedNode && selectedEggId ? 'current' : 'upcoming') },
            { id: 4, name: 'Payment', status: termsAgreed && privacyAgreed ? 'current' : 'upcoming' },
        ];
        return steps as { id: number; name: string; status: 'complete' | 'current' | 'upcoming' }[];
    };

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
            processUnpaidOrder(product.id, selectedNode, undefined, variables, undefined, couponData?.coupon.id, selectedEggId)
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

                // Initialize selected egg with the default (first allowed egg)
                const allowedEggs = productData.allowedEggs || [productData.eggId];
                setSelectedEggId(allowedEggs[0]);

                // Fetch egg information for all allowed eggs
                const eggInfoPromises = allowedEggs.map(id => getEggInfo(id));
                const eggInfos = await Promise.all(eggInfoPromises);
                setAvailableEggs(eggInfos);

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

        if (!product || eggs || !selectedEggId) return;

        // Fetch product variables (egg data) for the selected egg
        getProductVariables(selectedEggId)
            .then(data => setEggs(data))
            .catch(error => console.error(error));
    }, [product, selectedEggId]);

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
            <div className={'mb-8'}>
                <h1 className={'text-4xl font-bold text-gray-100'}>Complete Your Order</h1>
                <p className={'mt-2 text-base text-gray-400'}>
                    Customize your server configuration and complete your purchase.
                </p>
            </div>

            <CheckoutStepper steps={getCheckoutSteps()} />

            <div className={'mt-10 grid gap-8 lg:grid-cols-3 lg:gap-10'}>
                {/* Main Content Area */}
                <div className={'lg:col-span-2 space-y-8'}>
                    {/* Location Section */}
                    <section>
                        <div className={'mb-6'}>
                            <h2 className={'text-2xl font-bold text-gray-200'}>Choose a Location</h2>
                            <p className={'mt-1 text-sm text-gray-400'}>
                                Select where you want your server to be deployed.
                            </p>
                        </div>
                        {(!nodes || nodes.length < 1) && (
                            <Alert type={'danger'}>
                                There are no nodes available for deployment. Please contact an administrator.
                            </Alert>
                        )}
                        <div className={'grid gap-4 sm:grid-cols-2'}>
                            {nodes?.map(node => (
                                <NodeBox
                                    node={node}
                                    key={node.id}
                                    selected={selectedNode}
                                    setSelected={setSelectedNode}
                                />
                            ))}
                        </div>
                    </section>

                    {/* Server Type Section */}
                    {availableEggs.length > 1 && (
                        <section>
                            <div className={'mb-6'}>
                                <h2 className={'text-2xl font-bold text-gray-200'}>Server Type</h2>
                                <p className={'mt-1 text-sm text-gray-400'}>
                                    Select which type of server you want to create.
                                </p>
                            </div>
                            <Select
                                value={selectedEggId}
                                onChange={e => {
                                    setSelectedEggId(Number(e.currentTarget.value));
                                    setEggs(undefined);
                                }}
                            >
                                {availableEggs.map(egg => (
                                    <option key={egg.id} value={egg.id}>
                                        {egg.name}
                                    </option>
                                ))}
                            </Select>
                            {availableEggs.find(e => e.id === selectedEggId)?.description && (
                                <p className={'mt-3 text-sm text-gray-400'}>
                                    {availableEggs.find(e => e.id === selectedEggId)?.description}
                                </p>
                            )}
                        </section>
                    )}

                    {/* Variables Section */}
                    {eggs && eggs.length > 1 && (
                        <section>
                            <div className={'mb-6'}>
                                <h2 className={'text-2xl font-bold text-gray-200'}>Server Variables</h2>
                                <p className={'mt-1 text-sm text-gray-400'}>
                                    Configure your server settings before deployment.
                                </p>
                            </div>
                            <div className={'grid gap-4 sm:grid-cols-2'}>
                                {eggs?.map(variable => (
                                    <div key={variable.envVariable}>
                                        {variable.isEditable && <VariableBox variable={variable} vars={vars} />}
                                    </div>
                                ))}
                            </div>
                        </section>
                    )}

                    {/* Legal Section */}
                    <section>
                        <div className={'mb-6'}>
                            <h2 className={'text-2xl font-bold text-gray-200'}>Legal Agreements</h2>
                            <p className={'mt-1 text-sm text-gray-400'}>
                                Please review and accept our terms to continue.
                            </p>
                        </div>
                        <div className={'space-y-4'}>
                            <div
                                onClick={() => !termsAgreed && setTermsAgreed(true)}
                                className={classNames(
                                    'flex items-start gap-4 rounded-lg border-2 p-4 transition-all cursor-pointer',
                                    termsAgreed
                                        ? 'border-green-500 bg-green-500/10'
                                        : 'border-gray-600 bg-gray-800 hover:border-gray-500'
                                )}
                            >
                                <AdminCheckbox
                                    name={'terms'}
                                    checked={termsAgreed}
                                    onChange={() => setTermsAgreed(!termsAgreed)}
                                />
                                <div className={'flex-1'}>
                                    <p className={'text-sm font-medium text-gray-200'}>
                                        I agree to the{' '}
                                        <a
                                            href={billing.links.terms}
                                            target={'_blank'}
                                            rel={'noreferrer'}
                                            className={'font-semibold text-blue-400 hover:text-blue-300'}
                                            onClick={e => e.stopPropagation()}
                                        >
                                            Terms of Service <FontAwesomeIcon icon={faExternalLinkAlt} className={'text-xs'} />
                                        </a>
                                    </p>
                                    {termsAgreed && (
                                        <p className={'mt-1 text-xs text-green-400'}>✓ Accepted</p>
                                    )}
                                </div>
                            </div>
                            <div
                                onClick={() => !privacyAgreed && setPrivacyAgreed(true)}
                                className={classNames(
                                    'flex items-start gap-4 rounded-lg border-2 p-4 transition-all cursor-pointer',
                                    privacyAgreed
                                        ? 'border-green-500 bg-green-500/10'
                                        : 'border-gray-600 bg-gray-800 hover:border-gray-500'
                                )}
                            >
                                <AdminCheckbox
                                    name={'privacy'}
                                    checked={privacyAgreed}
                                    onChange={() => setPrivacyAgreed(!privacyAgreed)}
                                />
                                <div className={'flex-1'}>
                                    <p className={'text-sm font-medium text-gray-200'}>
                                        I agree to the{' '}
                                        <a
                                            href={billing.links.privacy}
                                            target={'_blank'}
                                            rel={'noreferrer'}
                                            className={'font-semibold text-blue-400 hover:text-blue-300'}
                                            onClick={e => e.stopPropagation()}
                                        >
                                            Privacy Policy <FontAwesomeIcon icon={faExternalLinkAlt} className={'text-xs'} />
                                        </a>
                                    </p>
                                    {privacyAgreed && (
                                        <p className={'mt-1 text-xs text-green-400'}>✓ Accepted</p>
                                    )}
                                </div>
                            </div>
                        </div>
                        {!termsAgreed || !privacyAgreed ? (
                            <Alert type={'warning'} className={'mt-4'}>
                                Please accept both agreements to proceed with your order.
                            </Alert>
                        ) : null}
                    </section>

                    {/* Coupon Section */}
                    {product.price !== 0 && (
                        <section>
                            <div className={'mb-6'}>
                                <h2 className={'text-2xl font-bold text-gray-200'}>Coupon Code</h2>
                                <p className={'mt-1 text-sm text-gray-400'}>
                                    Have a coupon? Apply it to get a discount.
                                </p>
                            </div>
                            <CouponInput subtotal={product.price} onCouponApplied={handleCouponApplied} />
                            <FlashMessageRender byKey={'coupon'} className={'mt-4'} />
                        </section>
                    )}

                    {/* Payment Section */}
                    {termsAgreed && privacyAgreed && (
                        <section>
                            <div className={'mb-6'}>
                                <h2 className={'text-2xl font-bold text-gray-200'}>Payment</h2>
                                <p className={'mt-1 text-sm text-gray-400'}>
                                    Complete your purchase to deploy your server.
                                </p>
                            </div>
                            {product.price === 0 || couponData?.total === 0 ? (
                                <div className={'rounded-lg border-2 border-green-500 bg-green-500/10 p-6'}>
                                    <p className={'mb-4 text-gray-200'}>
                                        {couponData?.total === 0
                                            ? '🎉 Your coupon has made this order free! No payment is required.'
                                            : '🎉 This product is free! No payment is required.'}
                                    </p>
                                    <Button onClick={createFree} size={Button.Sizes.Large}>
                                        Create Server
                                    </Button>
                                </div>
                            ) : intent ? (
                                <div className={'rounded-lg border border-gray-700 bg-gray-800 p-6'}>
                                    {/* @ts-expect-error this is fine, stripe library is just weird */}
                                    <Elements stripe={stripe} options={options} key={intent?.id}>
                                        <PaymentButton
                                            selectedNode={selectedNode}
                                            product={product}
                                            vars={vars}
                                            intent={intent}
                                            couponId={couponData?.coupon.id}
                                            selectedEggId={selectedEggId}
                                        />
                                    </Elements>
                                </div>
                            ) : (
                                <Spinner centered />
                            )}
                        </section>
                    )}
                </div>

                {/* Sidebar - Order Summary */}
                <div className={'lg:col-span-1'}>
                    <div className={'sticky top-4 rounded-lg border border-gray-700 bg-gray-800 p-6'}>
                        <h3 className={'mb-4 text-xl font-bold text-gray-200'}>Order Summary</h3>
                        
                        <div className={'mb-4 flex items-center gap-3'}>
                            {product.icon && <img src={product.icon} className={'h-10 w-10 rounded'} alt={product.name} />}
                            <div className={'flex-1'}>
                                <p className={'font-semibold text-gray-200'}>{product.name}</p>
                                <div className={'mt-1'}>
                                    {couponData ? (
                                        <div>
                                            <div className={'text-xs text-gray-400 line-through'}>${couponData.subtotal.toFixed(2)}</div>
                                            <div className={'flex items-baseline gap-1'}>
                                                <span className={'text-2xl font-bold'} style={{ color: colors.primary }}>
                                                    ${couponData.total.toFixed(2)}
                                                </span>
                                                <span className={'text-xs text-gray-400'}>/ month</span>
                                            </div>
                                            <div className={'text-xs font-medium text-green-400'}>Save ${couponData.discount.toFixed(2)}</div>
                                        </div>
                                    ) : (
                                        <div className={'flex items-baseline gap-1'}>
                                            <span className={'text-2xl font-bold'} style={{ color: colors.primary }}>
                                                ${product.price.toFixed(2)}
                                            </span>
                                            <span className={'text-xs text-gray-400'}>/ month</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        <div className={'my-4 h-px bg-gray-700'} />

                        <div className={'space-y-3'}>
                            <div className={'flex items-center gap-2 text-sm'}>
                                <FontAwesomeIcon icon={faMicrochip} className={'h-4 w-4 text-gray-400'} />
                                <span className={'text-gray-300'}>{product.limits.cpu}% CPU</span>
                            </div>
                            <div className={'flex items-center gap-2 text-sm'}>
                                <FontAwesomeIcon icon={faMemory} className={'h-4 w-4 text-gray-400'} />
                                <span className={'text-gray-300'}>{(product.limits.memory / 1024).toFixed(1)} GiB RAM</span>
                            </div>
                            <div className={'flex items-center gap-2 text-sm'}>
                                <FontAwesomeIcon icon={faHdd} className={'h-4 w-4 text-gray-400'} />
                                <span className={'text-gray-300'}>{(product.limits.disk / 1024).toFixed(1)} GiB Storage</span>
                            </div>
                        </div>

                        <div className={'my-4 h-px bg-gray-700'} />

                        <div className={'space-y-3'}>
                            <div className={'flex items-center gap-2 text-sm'}>
                                <FontAwesomeIcon icon={faArchive} className={'h-4 w-4 text-gray-400'} />
                                <span className={'text-gray-300'}>{product.limits.backup} Backups</span>
                            </div>
                            <div className={'flex items-center gap-2 text-sm'}>
                                <FontAwesomeIcon icon={faDatabase} className={'h-4 w-4 text-gray-400'} />
                                <span className={'text-gray-300'}>{product.limits.database} Databases</span>
                            </div>
                            <div className={'flex items-center gap-2 text-sm'}>
                                <FontAwesomeIcon icon={faEthernet} className={'h-4 w-4 text-gray-400'} />
                                <span className={'text-gray-300'}>{product.limits.allocation} Ports</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </PageContentBlock>
    );
};
