import Spinner from '@/elements/Spinner';
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useStoreState } from '@/state/hooks';
import NodeBox from '@account/billing/order/NodeBox';
import EggBox from '@account/billing/order/EggBox';
import PageContentBlock from '@/elements/PageContentBlock';
import VariableBox from '@account/billing/order/VariableBox';
import CouponInput from '@account/billing/order/CouponInput';
import CheckoutStepper from '@account/billing/order/CheckoutStepper';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faArchive,
    faDatabase,
    faEthernet,
    faExternalLinkAlt,
    faHdd,
    faMemory,
    faMicrochip,
} from '@fortawesome/free-solid-svg-icons';
import { Alert } from '@/elements/alert';
import useFlash from '@/plugins/useFlash';
import PaymentButton from './PaymentButton';
import MolliePaymentButton from './MolliePaymentButton';
import { Elements } from '@stripe/react-stripe-js';
import { loadStripe, Stripe } from '@stripe/stripe-js';
import { EggVariable } from '@definitions/server';
import { Button } from '@/elements/button';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { Product, StripeIntent, type Node } from '@definitions/account/billing';
import { processUnpaidOrder } from '@/api/routes/account/billing/orders/process';
import {
    getProduct,
    getProductVariables,
    getViableNodes,
    getEggInfo,
    type EggInfo,
} from '@/api/routes/account/billing/products';
import { getStripeIntent, getStripeKey } from '@/api/routes/account/billing/orders/stripe';
import AdminCheckbox from '@/elements/AdminCheckbox';
import { ValidateCouponResponse } from '@/api/routes/account/billing/coupons';
import classNames from 'classnames';

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
    const [serverName, setServerName] = useState<string>('');
    const [serverNameTouched, setServerNameTouched] = useState<boolean>(false);

    const { colors } = useStoreState(state => state.theme.data!);

    // Calculate checkout steps progress
    const getCheckoutSteps = () => {
        const isEggSelectionComplete = availableEggs.length <= 1 || selectedEggId;
        const steps = [
            { id: 1, name: 'Location', status: selectedNode ? 'complete' : 'current' },
            {
                id: 2,
                name: 'Configuration',
                status: selectedNode ? (isEggSelectionComplete ? 'complete' : 'current') : 'upcoming',
            },
            {
                id: 3,
                name: 'Legal',
                status:
                    termsAgreed && privacyAgreed
                        ? 'complete'
                        : selectedNode && isEggSelectionComplete
                        ? 'current'
                        : 'upcoming',
            },
            { id: 4, name: 'Payment', status: termsAgreed && privacyAgreed ? 'current' : 'upcoming' },
        ];
        return steps as { id: number; name: string; status: 'complete' | 'current' | 'upcoming' }[];
    };

    const handleCouponApplied = (data: ValidateCouponResponse | null) => {
        setCouponData(data);

        // Only regenerate intent if the final total is not zero and using Stripe
        if (product && product.price !== 0 && billing.processor === 'stripe') {
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
        if (product && serverName.trim()) {
            const variables = Array.from(vars, ([key, value]) => ({ key, value }));
            processUnpaidOrder(
                product.id,
                selectedNode,
                undefined,
                variables,
                undefined,
                couponData?.coupon.id,
                selectedEggId,
                serverName.trim(),
            )
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
                    // Only fetch Stripe resources if Stripe is the processor
                    if (billing.processor === 'stripe') {
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
                <div className={'space-y-8 lg:col-span-2'}>
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
                            <div className={'grid gap-4 sm:grid-cols-2'}>
                                {availableEggs.map(egg => (
                                    <EggBox
                                        egg={egg}
                                        key={egg.id}
                                        selected={selectedEggId}
                                        setSelected={setSelectedEggId}
                                        onEggChange={() => setEggs(undefined)}
                                    />
                                ))}
                            </div>
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
                </div>

                {/* Sidebar - Order Summary */}
                <div className={'lg:col-span-1'}>
                    <div className={'sticky top-4 space-y-6'}>
                        {/* Server Name Card */}
                        <div
                            style={{ backgroundColor: colors.secondary }}
                            className={'rounded-lg border border-gray-700 p-6'}
                        >
                            <h3 className={'mb-4 text-lg font-bold text-gray-200'}>Server Name</h3>
                            <p className={'mb-3 text-sm text-gray-400'}>Choose a name for your server.</p>
                            <input
                                type={'text'}
                                placeholder={'Enter server name'}
                                value={serverName}
                                onChange={e => setServerName(e.target.value)}
                                onBlur={() => setServerNameTouched(true)}
                                required
                                maxLength={191}
                                aria-invalid={serverNameTouched && !serverName.trim()}
                                aria-describedby={
                                    serverNameTouched && !serverName.trim() ? 'server-name-error' : undefined
                                }
                                className={classNames(
                                    'w-full rounded-lg border px-4 py-2.5 text-sm transition-all',
                                    'text-gray-200 placeholder-gray-500',
                                    'focus:border-primary focus:ring-primary/20 border-gray-600 focus:outline-none focus:ring-2',
                                )}
                                style={{
                                    backgroundColor: colors.secondary,
                                    borderColor: serverName.trim() ? colors.primary : undefined,
                                }}
                            />
                            {serverNameTouched && !serverName.trim() && (
                                <p
                                    id={'server-name-error'}
                                    className={'mt-2 text-xs text-amber-400'}
                                    role={'alert'}
                                    aria-live={'polite'}
                                >
                                    ⚠ Server name is required
                                </p>
                            )}
                        </div>

                        {/* Order Summary Card */}
                        <div
                            style={{ backgroundColor: colors.secondary }}
                            className={'rounded-lg border border-gray-700 p-6'}
                        >
                            <h3 className={'mb-4 text-xl font-bold text-gray-200'}>Order Summary</h3>

                            <div className={'mb-4 flex items-center gap-3'}>
                                {product.icon && (
                                    <img src={product.icon} className={'h-10 w-10 rounded'} alt={product.name} />
                                )}
                                <div className={'flex-1'}>
                                    <p className={'font-semibold text-gray-200'}>{product.name}</p>
                                    <div className={'mt-1'}>
                                        {couponData ? (
                                            <div>
                                                <div className={'text-xs text-gray-400 line-through'}>
                                                    ${couponData.subtotal.toFixed(2)}
                                                </div>
                                                <div className={'flex items-baseline gap-1'}>
                                                    <span
                                                        className={'text-2xl font-bold'}
                                                        style={{ color: colors.primary }}
                                                    >
                                                        ${couponData.total.toFixed(2)}
                                                    </span>
                                                    <span className={'text-xs text-gray-400'}>/ month</span>
                                                </div>
                                                <div
                                                    className={'text-xs font-medium'}
                                                    style={{ color: colors.primary }}
                                                >
                                                    Save ${couponData.discount.toFixed(2)}
                                                </div>
                                            </div>
                                        ) : (
                                            <div className={'flex items-baseline gap-1'}>
                                                <span
                                                    className={'text-2xl font-bold'}
                                                    style={{ color: colors.primary }}
                                                >
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
                                    <span className={'text-gray-300'}>
                                        {(product.limits.memory / 1024).toFixed(1)} GiB RAM
                                    </span>
                                </div>
                                <div className={'flex items-center gap-2 text-sm'}>
                                    <FontAwesomeIcon icon={faHdd} className={'h-4 w-4 text-gray-400'} />
                                    <span className={'text-gray-300'}>
                                        {(product.limits.disk / 1024).toFixed(1)} GiB Storage
                                    </span>
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

                        {/* Legal Agreements Card */}
                        <div
                            style={{ backgroundColor: colors.secondary }}
                            className={'rounded-lg border border-gray-700 p-6'}
                        >
                            <h3 className={'mb-4 text-lg font-bold text-gray-200'}>Legal Agreements</h3>
                            <div className={'space-y-3'}>
                                <div
                                    className={
                                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-all'
                                    }
                                    style={
                                        termsAgreed
                                            ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` }
                                            : { borderColor: '#374151', backgroundColor: colors.secondary }
                                    }
                                >
                                    <AdminCheckbox
                                        name={'terms'}
                                        checked={termsAgreed}
                                        onChange={() => setTermsAgreed(!termsAgreed)}
                                    />
                                    <div className={'min-w-0 flex-1'} onClick={() => setTermsAgreed(!termsAgreed)}>
                                        <p className={'text-xs font-medium text-gray-200'}>
                                            <a
                                                href={billing.links.terms}
                                                target={'_blank'}
                                                rel={'noreferrer'}
                                                className={'hover:brightness-125'}
                                                style={{ color: colors.primary }}
                                                onClick={e => e.stopPropagation()}
                                            >
                                                Terms of Service{' '}
                                                <FontAwesomeIcon icon={faExternalLinkAlt} className={'text-xs'} />
                                            </a>
                                        </p>
                                        {termsAgreed && (
                                            <p className={'mt-0.5 text-xs'} style={{ color: colors.primary }}>
                                                ✓ Accepted
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <div
                                    className={
                                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-all'
                                    }
                                    style={
                                        privacyAgreed
                                            ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` }
                                            : { borderColor: '#374151', backgroundColor: colors.secondary }
                                    }
                                >
                                    <AdminCheckbox
                                        name={'privacy'}
                                        checked={privacyAgreed}
                                        onChange={() => setPrivacyAgreed(!privacyAgreed)}
                                    />
                                    <div className={'min-w-0 flex-1'} onClick={() => setPrivacyAgreed(!privacyAgreed)}>
                                        <p className={'text-xs font-medium text-gray-200'}>
                                            <a
                                                href={billing.links.privacy}
                                                target={'_blank'}
                                                rel={'noreferrer'}
                                                className={'hover:brightness-125'}
                                                style={{ color: colors.primary }}
                                                onClick={e => e.stopPropagation()}
                                            >
                                                Privacy Policy{' '}
                                                <FontAwesomeIcon icon={faExternalLinkAlt} className={'text-xs'} />
                                            </a>
                                        </p>
                                        {privacyAgreed && (
                                            <p className={'mt-0.5 text-xs'} style={{ color: colors.primary }}>
                                                ✓ Accepted
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>
                            {!termsAgreed || !privacyAgreed ? (
                                <Alert type={'warning'} className={'mt-3'}>
                                    <p className={'text-xs'}>Please accept both agreements to proceed.</p>
                                </Alert>
                            ) : null}
                        </div>

                        {/* Coupon Section - Only show for paid products */}
                        {product.price !== 0 && (
                            <div
                                style={{ backgroundColor: colors.secondary }}
                                className={'rounded-lg border border-gray-700 p-6'}
                            >
                                <h3 className={'mb-4 text-lg font-bold text-gray-200'}>Coupon Code</h3>
                                <CouponInput subtotal={product.price} onCouponApplied={handleCouponApplied} />
                                <FlashMessageRender byKey={'coupon'} className={'mt-4'} />
                            </div>
                        )}

                        {/* Checkout Button Card */}
                        {termsAgreed && privacyAgreed && (
                            <div
                                style={{ backgroundColor: colors.secondary }}
                                className={'rounded-lg border border-gray-700 p-6'}
                            >
                                <h3 className={'mb-4 text-lg font-bold text-gray-200'}>Complete Order</h3>
                                {product.price === 0 || couponData?.total === 0 ? (
                                    <div>
                                        <p className={'mb-4 text-sm text-gray-300'}>
                                            {couponData?.total === 0
                                                ? '🎉 Your coupon has made this order free!'
                                                : '🎉 This product is free!'}
                                        </p>
                                        <Button
                                            onClick={createFree}
                                            size={Button.Sizes.Large}
                                            className={'w-full'}
                                            disabled={!serverName.trim()}
                                        >
                                            Create Server
                                        </Button>
                                    </div>
                                ) : intent && billing.processor === 'stripe' ? (
                                    <div>
                                        {/* @ts-expect-error this is fine, stripe library is just weird */}
                                        <Elements stripe={stripe} options={options} key={intent?.id}>
                                            <PaymentButton
                                                selectedNode={selectedNode}
                                                product={product}
                                                vars={vars}
                                                intent={intent}
                                                couponId={couponData?.coupon.id}
                                                selectedEggId={selectedEggId}
                                                serverName={serverName}
                                            />
                                        </Elements>
                                    </div>
                                ) : billing.processor === 'mollie' ? (
                                    <div>
                                        <MolliePaymentButton
                                            selectedNode={selectedNode}
                                            product={product}
                                            vars={vars}
                                            couponId={couponData?.coupon.id}
                                            selectedEggId={selectedEggId}
                                            serverName={serverName}
                                        />
                                    </div>
                                ) : (
                                    <Spinner centered />
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </PageContentBlock>
    );
};
