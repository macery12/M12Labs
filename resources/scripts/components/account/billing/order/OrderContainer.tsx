import Spinner from '@/elements/Spinner';
import { useEffect, useRef, useState, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useStoreState } from '@/state/hooks';
import NodeBox from '@account/billing/order/NodeBox';
import EggBox from '@account/billing/order/EggBox';
import BillingCycleBox from '@account/billing/order/BillingCycleBox';
import PageContentBlock from '@/elements/PageContentBlock';
import VariableBox from '@account/billing/order/VariableBox';
import CouponInput from '@account/billing/order/CouponInput';
import CheckoutStepper from '@account/billing/order/CheckoutStepper';
import PriceBreakdown from '@account/billing/order/PriceBreakdown';
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
    faSync,
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

    const [couponData, setCouponData] = useState<ValidateCouponResponse | null>(null);
    const [serverName, setServerName] = useState<string>('');
    const [serverNameTouched, setServerNameTouched] = useState<boolean>(false);
    const [showCoupon, setShowCoupon] = useState<boolean>(false);
    const [legalAgreed, setLegalAgreed] = useState<boolean>(false);
    
    // Wizard step state
    const [currentStep, setCurrentStep] = useState<number>(1);

    const { colors } = useStoreState(state => state.theme.data!);

    // Auto-generate server name
    const generateServerName = useCallback(() => {
        if (!product || !selectedNode || !selectedEggId) return '';
        
        const selectedEgg = availableEggs.find(e => e.id === selectedEggId);
        const node = nodes?.find(n => Number(n.id) === selectedNode);
        
        if (!selectedEgg || !node) return '';
        
        const eggName = selectedEgg.name.split(' ')[0] || 'Server';
        const nodePrefix = node.name.split('-')[0] || 'Node';
        const timestamp = Date.now().toString().slice(-6);
        
        return `${eggName}-${nodePrefix}-${timestamp}`;
    }, [product, selectedNode, selectedEggId, availableEggs, nodes]);

    // Get total number of steps dynamically
    const getTotalSteps = () => {
        let steps = 3; // Node, Billing, Review (always present)
        if (availableEggs.length > 1) steps++; // Add egg selection step if multiple eggs
        if (eggs && eggs.some(v => v.isEditable)) steps++; // Add variables step if editable vars exist
        return steps;
    };

    // Navigate to next step
    const goToNextStep = () => {
        const totalSteps = getTotalSteps();
        if (currentStep < totalSteps + 1) { // +1 for payment step
            setCurrentStep(currentStep + 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    // Navigate to previous step
    const goToPreviousStep = () => {
        if (currentStep > 1) {
            setCurrentStep(currentStep - 1);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };

    // Check if current step is valid
    const isStepValid = (step: number) => {
        switch (step) {
            case 1: // Node selection
                return selectedNode !== 0;
            case 2: // Egg selection (if applicable)
                if (availableEggs.length <= 1) return true;
                return selectedEggId !== undefined;
            case 3: // Billing cycle
                return selectedBillingDays !== 0;
            case 4: // Variables (if applicable)
                return true; // Variables are optional
            case 5: // Review
                return serverName.trim() !== '' && legalAgreed;
            default:
                return false;
        }
    };

    // Get step title
    const getStepTitle = (step: number) => {
        const stepMap: { [key: number]: string } = {};
        let stepNum = 1;
        
        stepMap[stepNum++] = 'Select Location';
        if (availableEggs.length > 1) stepMap[stepNum++] = 'Select Server Type';
        stepMap[stepNum++] = 'Select Billing Cycle';
        if (eggs && eggs.some(v => v.isEditable)) stepMap[stepNum++] = 'Configure Server';
        stepMap[stepNum++] = 'Review & Confirm';
        stepMap[stepNum++] = 'Payment';
        
        return stepMap[step] || '';
    };

    // Get the current price based on selected billing cycle
    const getCurrentPrice = () => {
        const selectedCycle = billingCycles.find(c => c.days === selectedBillingDays);
        return selectedCycle ? selectedCycle.price : product?.price ?? 0;
    };

    
    // Get progress steps for wizard
    const getWizardSteps = () => {
        const steps = [];
        let stepNum = 1;
        
        // Step 1: Node selection
        steps.push({
            id: stepNum++,
            name: 'Location',
            status: currentStep > 1 ? 'complete' : (currentStep === 1 ? 'current' : 'upcoming')
        });
        
        // Step 2: Egg selection (if multiple eggs)
        if (availableEggs.length > 1) {
            const eggStep = stepNum++;
            steps.push({
                id: eggStep,
                name: 'Server Type',
                status: currentStep > eggStep ? 'complete' : (currentStep === eggStep ? 'current' : 'upcoming')
            });
        }
        
        // Step 3: Billing cycle
        const billingStep = stepNum++;
        steps.push({
            id: billingStep,
            name: 'Billing',
            status: currentStep > billingStep ? 'complete' : (currentStep === billingStep ? 'current' : 'upcoming')
        });
        
        // Step 4: Variables (if editable variables exist)
        if (eggs && eggs.some(v => v.isEditable)) {
            const varsStep = stepNum++;
            steps.push({
                id: varsStep,
                name: 'Configure',
                status: currentStep > varsStep ? 'complete' : (currentStep === varsStep ? 'current' : 'upcoming')
            });
        }
        
        // Step 5: Review
        const reviewStep = stepNum++;
        steps.push({
            id: reviewStep,
            name: 'Review',
            status: currentStep > reviewStep ? 'complete' : (currentStep === reviewStep ? 'current' : 'upcoming')
        });
        
        // Step 6: Payment
        steps.push({
            id: stepNum,
            name: 'Payment',
            status: currentStep >= stepNum ? 'current' : 'upcoming'
        });
        
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
    }, [selectedNode, selectedEggId, product, serverNameTouched, generateServerName]);

    // Clear coupon when billing cycle changes to prevent showing incorrect totals
    useEffect(() => {
        if (couponData) {
            // Clear the coupon data to force revalidation with new price
            setCouponData(null);
        }
        // Note: couponData is intentionally NOT in the dependency array to avoid infinite loops
        // We only want to clear it when selectedBillingDays changes
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedBillingDays]);

    if (!product) return <Spinner centered />;

    if (!product) return <Spinner centered />;

    // Render different content based on current step
    const renderStepContent = () => {
        let actualStep = currentStep;
        
        // Adjust step numbers if egg selection is skipped
        if (availableEggs.length <= 1 && currentStep >= 2) {
            actualStep++;
        }
        
        // Adjust if variables are skipped
        const hasEditableVars = eggs && eggs.some(v => v.isEditable);
        if (!hasEditableVars && currentStep >= (availableEggs.length > 1 ? 4 : 3)) {
            actualStep++;
        }

        switch (currentStep) {
            case 1: // Node Selection
                return (
                    <div className={'space-y-6'}>
                        <div>
                            <h2 className={'text-3xl font-bold text-gray-100'}>Choose Your Server Location</h2>
                            <p className={'mt-2 text-gray-400'}>
                                Select the datacenter where you want your server to be deployed.
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
                                    basePrice={getCurrentPrice()}
                                    billingDays={selectedBillingDays}
                                />
                            ))}
                        </div>
                    </div>
                );

            case 2: // Egg Selection (only if multiple eggs)
                if (availableEggs.length <= 1) return null;
                return (
                    <div className={'space-y-6'}>
                        <div>
                            <h2 className={'text-3xl font-bold text-gray-100'}>Select Server Type</h2>
                            <p className={'mt-2 text-gray-400'}>
                                Choose which type of server software you want to run.
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
                    </div>
                );

            case (availableEggs.length > 1 ? 3 : 2): // Billing Cycle
                return (
                    <div className={'space-y-6'}>
                        <div>
                            <h2 className={'text-3xl font-bold text-gray-100'}>Choose Billing Cycle</h2>
                            <p className={'mt-2 text-gray-400'}>
                                Select how often you want to be billed for this server.
                            </p>
                        </div>
                        <div className={'space-y-3'}>
                            {billingCycles.map(cycle => (
                                <BillingCycleBox
                                    cycle={cycle}
                                    key={cycle.days}
                                    selected={selectedBillingDays}
                                    setSelected={setSelectedBillingDays}
                                />
                            ))}
                        </div>
                    </div>
                );

            case (availableEggs.length > 1 ? 4 : 3): // Variables (if any)
                if (!eggs || !eggs.some(v => v.isEditable)) return null;
                return (
                    <div className={'space-y-6'}>
                        <div>
                            <h2 className={'text-3xl font-bold text-gray-100'}>Configure Server</h2>
                            <p className={'mt-2 text-gray-400'}>
                                Set up your server variables and configuration.
                            </p>
                        </div>
                        <div className={'grid gap-4 sm:grid-cols-2'}>
                            {eggs?.map(variable => (
                                <div key={variable.envVariable}>
                                    {variable.isEditable && <VariableBox variable={variable} vars={vars} />}
                                </div>
                            ))}
                        </div>
                    </div>
                );

            default: // Review & Name Server
                const finalStep = getTotalSteps();
                if (currentStep === finalStep) {
                    const getCurrentPrice = () => {
                        const selectedCycle = billingCycles.find(c => c.days === selectedBillingDays);
                        return selectedCycle ? selectedCycle.price : product?.price ?? 0;
                    };

                    return (
                        <div className={'space-y-6'}>
                            <div>
                                <h2 className={'text-3xl font-bold text-gray-100'}>Review Your Order</h2>
                                <p className={'mt-2 text-gray-400'}>
                                    Review your selections and name your server before proceeding to payment.
                                </p>
                            </div>

                            {/* Server Name Section */}
                            <div
                                className={'rounded-lg border p-6'}
                                style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}
                            >
                                <div className={'mb-4 flex items-center justify-between'}>
                                    <h3 className={'text-xl font-bold text-gray-200'}>Server Name</h3>
                                    <button
                                        onClick={() => {
                                            const name = generateServerName();
                                            if (name) {
                                                setServerName(name);
                                                setServerNameTouched(false);
                                            }
                                        }}
                                        className={'flex items-center gap-1 text-xs font-medium hover:brightness-125 transition-all'}
                                        style={{ color: colors.primary }}
                                        disabled={!selectedNode || !selectedEggId}
                                        aria-label={'Auto-generate server name'}
                                    >
                                        <FontAwesomeIcon icon={faSync} className={'h-3 w-3'} />
                                        Generate
                                    </button>
                                </div>
                                <input
                                    type={'text'}
                                    placeholder={'Enter server name'}
                                    value={serverName}
                                    onChange={e => {
                                        setServerName(e.target.value);
                                        setServerNameTouched(true);
                                    }}
                                    required
                                    maxLength={191}
                                    className={classNames(
                                        'w-full rounded-lg border px-4 py-3 text-sm transition-all',
                                        'text-gray-200 placeholder-gray-500',
                                        'focus:border-primary focus:ring-primary/20 border-gray-600 focus:outline-none focus:ring-2',
                                    )}
                                    style={{
                                        backgroundColor: colors.secondary,
                                        borderColor: serverName.trim() ? colors.primary : undefined,
                                    }}
                                />
                                {serverNameTouched && !serverName.trim() && (
                                    <p className={'mt-2 text-xs text-amber-400'}>⚠ Server name is required</p>
                                )}
                            </div>

                            {/* Order Summary */}
                            <div
                                className={'rounded-lg border p-6'}
                                style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}
                            >
                                <h3 className={'mb-4 text-xl font-bold text-gray-200'}>Order Summary</h3>
                                
                                <div className={'space-y-4'}>
                                    <div className={'flex items-start justify-between'}>
                                        <div className={'flex items-center gap-3'}>
                                            {product.icon && (
                                                <img src={product.icon} className={'h-12 w-12 rounded'} alt={product.name} />
                                            )}
                                            <div>
                                                <p className={'font-semibold text-gray-200'}>{product.name}</p>
                                                <p className={'text-sm text-gray-400'}>
                                                    {selectedBillingDays} {selectedBillingDays === 1 ? 'day' : 'days'}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div className={'border-t border-gray-700 pt-4'}>
                                        <div className={'grid grid-cols-2 gap-4'}>
                                            <div>
                                                <p className={'text-xs text-gray-500'}>Location</p>
                                                <p className={'text-sm font-medium text-gray-200'}>
                                                    {nodes?.find(n => Number(n.id) === selectedNode)?.name}
                                                </p>
                                            </div>
                                            {availableEggs.length > 1 && (
                                                <div>
                                                    <p className={'text-xs text-gray-500'}>Server Type</p>
                                                    <p className={'text-sm font-medium text-gray-200'}>
                                                        {availableEggs.find(e => e.id === selectedEggId)?.name}
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    <div className={'border-t border-gray-700 pt-4'}>
                                        <div className={'grid grid-cols-3 gap-4'}>
                                            <div>
                                                <p className={'text-xs text-gray-500'}>CPU</p>
                                                <p className={'text-sm font-medium text-gray-200'}>{product.limits.cpu}%</p>
                                            </div>
                                            <div>
                                                <p className={'text-xs text-gray-500'}>RAM</p>
                                                <p className={'text-sm font-medium text-gray-200'}>
                                                    {(product.limits.memory / 1024).toFixed(1)} GB
                                                </p>
                                            </div>
                                            <div>
                                                <p className={'text-xs text-gray-500'}>Storage</p>
                                                <p className={'text-sm font-medium text-gray-200'}>
                                                    {(product.limits.disk / 1024).toFixed(1)} GB
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Price Breakdown */}
                            {product.price !== 0 && (() => {
                                const selectedCycle = billingCycles.find(c => c.days === selectedBillingDays);
                                const selectedNodeData = nodes?.find(n => Number(n.id) === selectedNode);
                                
                                return (
                                    <PriceBreakdown
                                        basePrice={product.price}
                                        billingDays={selectedBillingDays}
                                        billingMultiplier={selectedCycle?.multiplier || 1.0}
                                        billingDiscountPercent={selectedCycle?.discountPercent || 0}
                                        nodeMultiplier={selectedNodeData?.priceMultiplier || 1.0}
                                        nodeName={selectedNodeData?.name}
                                        couponDiscount={couponData?.discount || 0}
                                        couponCode={couponData?.coupon.code}
                                    />
                                );
                            })()}

                            {/* Legal Agreements */}
                            <div
                                className={'rounded-lg border p-6'}
                                style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}
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

                            {/* Coupon Section */}
                            {product.price !== 0 && (
                                <div
                                    className={'rounded-lg border p-6'}
                                    style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}
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
                                        <div>
                                            <CouponInput subtotal={product.price} onCouponApplied={handleCouponApplied} />
                                            <FlashMessageRender byKey={'coupon'} className={'mt-4'} />
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    );
                }

                // Payment step
                if (currentStep === finalStep + 1) {
                    const getCurrentPrice = () => {
                        const selectedCycle = billingCycles.find(c => c.days === selectedBillingDays);
                        return selectedCycle ? selectedCycle.price : product?.price ?? 0;
                    };

                    return (
                        <div className={'space-y-6'}>
                            <div>
                                <h2 className={'text-3xl font-bold text-gray-100'}>Complete Payment</h2>
                                <p className={'mt-2 text-gray-400'}>
                                    Choose your payment method to complete your order.
                                </p>
                            </div>

                            <div
                                className={'rounded-lg border p-6'}
                                style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}
                            >
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
                        </div>
                    );
                }
                return null;
        }
    };

    return (
        <PageContentBlock title={'Your Order'}>
            <FlashMessageRender byKey={'account:billing:order'} className={'mb-4'} />
            
            {/* Header */}
            <div className={'mb-8'}>
                <h1 className={'text-4xl font-bold text-gray-100'}>Order Your Server</h1>
                <p className={'mt-2 text-base text-gray-400'}>
                    Follow the steps below to configure and purchase your server.
                </p>
            </div>

            {/* Progress Stepper */}
            <CheckoutStepper steps={getWizardSteps()} />

            {/* Main Wizard Content */}
            <div className={'mt-10'}>
                <div className={'mx-auto max-w-4xl'}>
                    {renderStepContent()}
                </div>
            </div>

            {/* Navigation Buttons */}
            <div className={'mt-8 flex items-center justify-between border-t border-gray-700 pt-6'}>
                <div>
                    {currentStep > 1 && currentStep <= getTotalSteps() && (
                        <Button.Text
                            onClick={goToPreviousStep}
                            variant={Button.Variants.Secondary}
                        >
                            ← Back
                        </Button.Text>
                    )}
                </div>
                <div>
                    {currentStep < getTotalSteps() && (
                        <Button
                            onClick={goToNextStep}
                            size={Button.Sizes.Large}
                            disabled={!isStepValid(currentStep)}
                        >
                            {currentStep === getTotalSteps() - 1 ? 'Continue to Review' : 'Next →'}
                        </Button>
                    )}
                    {currentStep === getTotalSteps() && (
                        <Button
                            onClick={goToNextStep}
                            size={Button.Sizes.Large}
                            disabled={!serverName.trim() || !legalAgreed}
                        >
                            Continue to Payment →
                        </Button>
                    )}
                </div>
            </div>
        </PageContentBlock>
    );
};
