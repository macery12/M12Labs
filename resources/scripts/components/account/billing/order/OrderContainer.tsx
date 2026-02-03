import Spinner from '@/elements/Spinner';
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useStoreState } from '@/state/hooks';
import NodeBox from '@account/billing/order/NodeBox';
import EggBox from '@account/billing/order/EggBox';
import BillingCycleBox from '@account/billing/order/BillingCycleBox';
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
    faChevronDown,
    faChevronRight,
} from '@fortawesome/free-solid-svg-icons';
import { Alert } from '@/elements/alert';
import useFlash from '@/plugins/useFlash';
import PaymentMethodSelector from './PaymentMethodSelector';
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
    getProductBillingCycles,
    type EggInfo,
    type BillingCycle,
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
    const [billingCycles, setBillingCycles] = useState<BillingCycle[]>([]);
    const [selectedBillingDays, setSelectedBillingDays] = useState<number>(30);
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
    const [showCoupon, setShowCoupon] = useState<boolean>(false);
    const [legalAgreed, setLegalAgreed] = useState<boolean>(false);

    const { colors } = useStoreState(state => state.theme.data!);

    // Auto-generate server name
    const generateServerName = () => {
        if (!product || !selectedNode || !selectedEggId) return '';
        
        const selectedEgg = availableEggs.find(e => e.id === selectedEggId);
        const node = nodes?.find(n => Number(n.id) === selectedNode);
        
        if (!selectedEgg || !node) return '';
        
        const eggName = selectedEgg.name.split(' ')[0] || 'Server';
        const nodePrefix = node.name.split('-')[0] || 'Node';
        const timestamp = Date.now().toString().slice(-6);
        
        return `${eggName}-${nodePrefix}-${timestamp}`;
    };

    // Get CTA button text and state
    const getCTAState = () => {
        const isEggSelectionComplete = availableEggs.length <= 1 || selectedEggId;
        
        if (!selectedNode) {
            return {
                text: 'Continue to Server Type',
                disabled: true,
                reason: 'Please select a location to continue'
            };
        }
        
        if (!isEggSelectionComplete) {
            return {
                text: 'Continue to Billing',
                disabled: true,
                reason: 'Please select a server type to continue'
            };
        }
        
        if (!selectedBillingDays) {
            return {
                text: 'Review & Pay',
                disabled: true,
                reason: 'Please select a billing cycle to continue'
            };
        }
        
        if (!serverName.trim()) {
            return {
                text: 'Review & Pay',
                disabled: true,
                reason: 'Please enter a server name to continue'
            };
        }
        
        if (!legalAgreed) {
            return {
                text: 'Review & Pay',
                disabled: true,
                reason: 'Please accept the legal agreements to continue'
            };
        }
        
        return {
            text: 'Review & Pay',
            disabled: false,
            reason: ''
        };
    };

    // Check if checkout is complete
    const isCheckoutComplete = () => {
        const isEggSelectionComplete = availableEggs.length <= 1 || selectedEggId;
        return legalAgreed && serverName.trim() && selectedNode && isEggSelectionComplete && selectedBillingDays;
    };

    // Get the current price based on selected billing cycle
    const getCurrentPrice = () => {
        const selectedCycle = billingCycles.find(c => c.days === selectedBillingDays);
        return selectedCycle ? selectedCycle.price : product?.price ?? 0;
    };

    // Calculate checkout steps progress - New flow: Node → Egg → Billing Cycle → Review & Payment
    const getCheckoutSteps = () => {
        const isEggSelectionComplete = availableEggs.length <= 1 || selectedEggId;
        const steps = [
            { id: 1, name: 'Location', status: selectedNode ? 'complete' : 'current' },
            {
                id: 2,
                name: 'Server Type',
                status: selectedNode ? (isEggSelectionComplete ? 'complete' : 'current') : 'upcoming',
            },
            {
                id: 3,
                name: 'Billing',
                status: selectedNode && isEggSelectionComplete ? (selectedBillingDays ? 'complete' : 'current') : 'upcoming',
            },
            {
                id: 4,
                name: 'Review & Payment',
                status: selectedNode && isEggSelectionComplete && selectedBillingDays ? 'current' : 'upcoming',
            },
        ];
        return steps as { id: number; name: string; status: 'complete' | 'current' | 'upcoming' }[];
    };

    const handleCouponApplied = (data: ValidateCouponResponse | null) => {
        setCouponData(data);

        // Only regenerate intent if the final total is not zero and using Stripe
        if (product && product.price !== 0 && billing.processors?.stripe?.available) {
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

                // Fetch billing cycles
                const cyclesData = await getProductBillingCycles(Number(params.id));
                setBillingCycles(cyclesData);
                
                // Set default billing cycle (find the default one or use the first one)
                const defaultCycle = cyclesData.find(c => c.isDefault) || cyclesData[0];
                if (defaultCycle) {
                    setSelectedBillingDays(defaultCycle.days);
                }

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
                    // Check which processors are available and fetch resources accordingly
                    const stripeAvailable = billing.processors?.stripe?.available ?? false;

                    // Fetch Stripe resources if Stripe is available
                    if (stripeAvailable) {
                        try {
                            // Fetch payment intent
                            const intentData = await getStripeIntent(Number(params.id));
                            setIntent({ id: intentData.id, secret: intentData.secret });

                            // Fetch Stripe public key and initialize Stripe
                            const stripePublicKey = await getStripeKey(Number(params.id));
                            const stripeInstance = await loadStripe(stripePublicKey.key);
                            setStripe(stripeInstance);
                        } catch (error) {
                            console.error('Error initializing Stripe:', error);
                        }
                    }

                    // Mollie doesn't need pre-initialization like Stripe
                    // Payment is created when user clicks the button
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

    // Auto-generate server name when selections change
    useEffect(() => {
        if (!serverNameTouched && product && selectedNode && selectedEggId) {
            const generatedName = generateServerName();
            if (generatedName) {
                setServerName(generatedName);
            }
        }
    }, [selectedNode, selectedEggId, product, serverNameTouched, availableEggs, nodes]);

    // Clear coupon when billing cycle changes to prevent showing incorrect totals
    useEffect(() => {
        if (couponData) {
            // Clear the coupon data to force revalidation with new price
            setCouponData(null);
        }
    }, [selectedBillingDays]);

    if (!product) return <Spinner centered />;

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
                    {/* Location Section - STEP 1 */}
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

                    {/* Server Type Section - STEP 2 */}
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

                    {/* Billing Cycle Section - STEP 3 */}
                    {billingCycles.length > 0 && (
                        <section>
                            <div className={'mb-6'}>
                                <h2 className={'text-2xl font-bold text-gray-200'}>Choose Your Billing Cycle</h2>
                                <p className={'mt-1 text-sm text-gray-400'}>
                                    Select how often you want to be billed for this server.
                                </p>
                            </div>
                            <div className={'grid gap-4 sm:grid-cols-2'}>
                                {billingCycles.map(cycle => (
                                    <BillingCycleBox
                                        cycle={cycle}
                                        key={cycle.days}
                                        selected={selectedBillingDays}
                                        setSelected={setSelectedBillingDays}
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
                        {/* Server Name Card with Auto-generate */}
                        <div
                            style={{ backgroundColor: colors.secondary }}
                            className={'rounded-lg border border-gray-700 p-6'}
                        >
                            <div className={'mb-4 flex items-center justify-between'}>
                                <h3 className={'text-lg font-bold text-gray-200'}>Server Name</h3>
                                <button
                                    onClick={() => {
                                        const name = generateServerName();
                                        if (name) {
                                            setServerName(name);
                                            setServerNameTouched(false);
                                        }
                                    }}
                                    className={'text-xs font-medium hover:brightness-125 transition-all'}
                                    style={{ color: colors.primary }}
                                    disabled={!selectedNode || !selectedEggId}
                                    aria-label={'Auto-generate server name'}
                                >
                                    ↻ Auto-generate
                                </button>
                            </div>
                            <p className={'mb-3 text-sm text-gray-400'}>Choose a name for your server.</p>
                            <input
                                type={'text'}
                                placeholder={'Enter server name'}
                                value={serverName}
                                onChange={e => {
                                    setServerName(e.target.value);
                                    setServerNameTouched(true);
                                }}
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

                        {/* Order Summary Card with Due Today */}
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
                                                    <span className={'text-xs text-gray-400'}>
                                                        / {selectedBillingDays} {selectedBillingDays === 1 ? 'day' : 'days'}
                                                    </span>
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
                                                    ${getCurrentPrice().toFixed(2)}
                                                </span>
                                                <span className={'text-xs text-gray-400'}>
                                                    / {selectedBillingDays} {selectedBillingDays === 1 ? 'day' : 'days'}
                                                </span>
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

                            {/* Due Today Section */}
                            {product.price !== 0 && (
                                <>
                                    <div className={'my-4 h-px bg-gray-700'} />
                                    <div className={'flex items-center justify-between'}>
                                        <span className={'text-base font-bold text-gray-200'}>Due Today</span>
                                        <span className={'text-xl font-bold'} style={{ color: colors.primary }}>
                                            ${couponData ? couponData.total.toFixed(2) : getCurrentPrice().toFixed(2)}
                                        </span>
                                    </div>
                                </>
                            )}
                        </div>

                        {/* Collapsed Legal Agreements Card */}
                        <div
                            style={{ backgroundColor: colors.secondary }}
                            className={'rounded-lg border border-gray-700 p-6'}
                        >
                            <h3 className={'mb-4 text-lg font-bold text-gray-200'}>Legal Agreements</h3>
                            <div
                                className={'flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-all'}
                                style={
                                    legalAgreed
                                        ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` }
                                        : { borderColor: '#374151', backgroundColor: colors.secondary }
                                }
                                onClick={() => setLegalAgreed(!legalAgreed)}
                            >
                                <AdminCheckbox
                                    name={'legal'}
                                    checked={legalAgreed}
                                    onChange={() => setLegalAgreed(!legalAgreed)}
                                />
                                <div className={'min-w-0 flex-1'}>
                                    <p className={'text-sm font-medium text-gray-200'}>
                                        I agree to the{' '}
                                        <a
                                            href={billing.links.terms}
                                            target={'_blank'}
                                            rel={'noreferrer'}
                                            className={'hover:brightness-125'}
                                            style={{ color: colors.primary }}
                                            onClick={e => e.stopPropagation()}
                                        >
                                            Terms of Service
                                            <FontAwesomeIcon icon={faExternalLinkAlt} className={'ml-1 text-xs'} />
                                        </a>
                                        {' and '}
                                        <a
                                            href={billing.links.privacy}
                                            target={'_blank'}
                                            rel={'noreferrer'}
                                            className={'hover:brightness-125'}
                                            style={{ color: colors.primary }}
                                            onClick={e => e.stopPropagation()}
                                        >
                                            Privacy Policy
                                            <FontAwesomeIcon icon={faExternalLinkAlt} className={'ml-1 text-xs'} />
                                        </a>
                                    </p>
                                    {legalAgreed && (
                                        <p className={'mt-1 text-xs'} style={{ color: colors.primary }}>
                                            ✓ Accepted
                                        </p>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Coupon Section with Toggle - Only show for paid products */}
                        {product.price !== 0 && (
                            <div
                                style={{ backgroundColor: colors.secondary }}
                                className={'rounded-lg border border-gray-700 p-6'}
                            >
                                <button
                                    onClick={() => setShowCoupon(!showCoupon)}
                                    className={'mb-3 flex w-full items-center justify-between text-left transition-all'}
                                    aria-label={showCoupon ? 'Hide coupon code input' : 'Show coupon code input'}
                                    aria-expanded={showCoupon}
                                >
                                    <h3 className={'text-lg font-bold text-gray-200'}>
                                        {showCoupon ? 'Coupon Code' : 'Have a coupon?'}
                                    </h3>
                                    <FontAwesomeIcon 
                                        icon={showCoupon ? faChevronDown : faChevronRight} 
                                        className={'h-3 w-3'}
                                        style={{ color: colors.primary }}
                                        aria-hidden={true}
                                    />
                                </button>
                                {showCoupon && (
                                    <div className={'animate-fadeIn'}>
                                        <CouponInput subtotal={product.price} onCouponApplied={handleCouponApplied} />
                                        <FlashMessageRender byKey={'coupon'} className={'mt-4'} />
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Sticky CTA Footer */}
            <div className={'mt-8 lg:sticky lg:bottom-4'}>
                <div
                    className={'rounded-lg border p-6'}
                    style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}
                >
                    {/* Show CTA message when conditions not met */}
                    {getCTAState().disabled && getCTAState().reason && (
                        <div className={'mb-4 rounded-lg border border-amber-500 bg-amber-500 bg-opacity-10 p-4'}>
                            <p className={'text-sm text-amber-400'}>⚠ {getCTAState().reason}</p>
                        </div>
                    )}

                    {/* Payment Section */}
                    {isCheckoutComplete() ? (
                        <div>
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
                                    >
                                        Create Server
                                    </Button>
                                </div>
                            ) : (
                                <PaymentMethodSelector
                                    selectedNode={selectedNode}
                                    product={product}
                                    vars={vars}
                                    intent={intent}
                                    stripe={stripe}
                                    couponId={couponData?.coupon.id}
                                    selectedEggId={selectedEggId}
                                    serverName={serverName}
                                />
                            )}
                        </div>
                    ) : (
                        <div className={'text-center'}>
                            <p className={'text-sm text-gray-400'}>
                                Complete all required steps to proceed with payment
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </PageContentBlock>
    );
};
