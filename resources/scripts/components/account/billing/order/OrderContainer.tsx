import Spinner from '@/elements/Spinner';
import { useEffect, useRef, useState, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useStoreState } from '@/state/hooks';
import NodeBox from '@account/billing/order/NodeBox';
import EggBox from '@account/billing/order/EggBox';
import BillingCycleBox from '@account/billing/order/BillingCycleBox';
import PageContentBlock from '@/elements/PageContentBlock';
import VariableBox from '@account/billing/order/VariableBox';
import CheckoutStepper from '@account/billing/order/CheckoutStepper';
import SubtotalCard from '@account/billing/order/SubtotalCard';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExternalLinkAlt, faHdd, faMemory, faMicrochip } from '@fortawesome/free-solid-svg-icons';
import { Alert } from '@/elements/alert';
import useFlash from '@/plugins/useFlash';
import PaymentMethodSelector from './PaymentMethodSelector';
import { Stripe } from '@stripe/stripe-js';
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
import { loadStripeOnce } from '@/lib/stripe';

const getResponseStatus = (reason: unknown): number | undefined => {
    if (typeof reason === 'object' && reason !== null) {
        const response = (reason as { response?: { status?: number } }).response;
        return response?.status;
    }
    return undefined;
};

export default () => {
    const params = useParams<'id'>();

    const vars = useRef(new Map<string, string>()).current;
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();
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
    const [legalAgreed, setLegalAgreed] = useState<boolean>(false);
    const [, setVarsUpdateCount] = useState<number>(0);

    const hasValidSelectedNode = Number.isInteger(selectedNode) && selectedNode > 0;
    const hasEditableVariables = eggs?.some(v => v.isEditable) ?? false;
    const TOTAL_STEPS = 3;
    const PAYMENT_STEP = TOTAL_STEPS + 1;
    const reviewStep = TOTAL_STEPS;

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

    const areRequiredVariablesFilled = () => {
        if (!eggs || eggs.length === 0) return true;

        return eggs.every(variable => {
            if (!variable.isEditable) return true;

            const isRequired = variable.rules.some(rule => rule.includes('required'));
            if (!isRequired) return true;

            const value = vars.get(variable.envVariable) ?? variable.serverValue ?? variable.defaultValue;
            return typeof value === 'string' && value.trim().length > 0;
        });
    };

    // Navigate to next step
    const goToNextStep = () => {
        if (currentStep < PAYMENT_STEP) {
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
        if (step === 1) return hasValidSelectedNode && selectedBillingDays !== 0; // Billing & node selection
        if (step === 2)
            return (
                availableEggs.length > 0 &&
                selectedEggId !== undefined &&
                eggs !== undefined &&
                (!hasEditableVariables || areRequiredVariablesFilled())
            ); // Server type & variables
        if (step === reviewStep) return serverName.trim() !== '' && legalAgreed; // Review
        return false;
    };

    // Get step title
    const getStepTitle = (step: number) => {
        const stepMap: { [key: number]: string } = {};
        let stepNum = 1;

        stepMap[stepNum++] = 'Billing';
        stepMap[stepNum++] = 'Server Type';
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

        // Step 1: Billing (location + cycle)
        steps.push({
            id: stepNum++,
            name: 'Billing',
            status: currentStep > 1 ? 'complete' : currentStep === 1 ? 'current' : 'upcoming',
        });

        // Step 2: Server Type
        const serverTypeStep = stepNum++;
        steps.push({
            id: serverTypeStep,
            name: 'Server Type',
            status: currentStep > serverTypeStep ? 'complete' : currentStep === serverTypeStep ? 'current' : 'upcoming',
        });

        // Step 3: Review
        const reviewStep = stepNum++;
        steps.push({
            id: reviewStep,
            name: 'Review',
            status: currentStep > reviewStep ? 'complete' : currentStep === reviewStep ? 'current' : 'upcoming',
        });

        // Step 4: Payment
        steps.push({
            id: stepNum,
            name: 'Payment',
            status: currentStep > reviewStep ? 'current' : 'upcoming',
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

                const eggResults = await Promise.allSettled(allowedEggs.map(id => getEggInfo(id)));
                const available: EggInfo[] = [];
                let removedMissingEggs = false;

                eggResults.forEach(result => {
                    if (result.status === 'fulfilled') {
                        available.push(result.value);
                        return;
                    }

                    const responseStatus = getResponseStatus(result.reason);

                    if (responseStatus === 404) {
                        removedMissingEggs = true;
                        return;
                    }

                    // Attach the original error as the cause for debugging.
                    const message = responseStatus
                        ? `Failed to fetch server software details (HTTP ${responseStatus}).`
                        : 'Unexpected error while fetching server software details.';
                    throw new Error(message, { cause: result.reason });
                });

                    if (removedMissingEggs) {
                        addFlash({
                            key: 'account:billing:order',
                            type: 'warning',
                            message: 'Some server software options are no longer available and were removed from selection.',
                        });
                    }

                setAvailableEggs(available);
                if (available.length > 0) {
                    setSelectedEggId(available[0].id);
                } else {
                    // Clear selections and variables when no eggs remain.
                    setSelectedEggId(undefined);
                    setEggs(undefined);
                }

                // Fetch nodes
                const nodesData = await getViableNodes(productData.id);
                setNodes(nodesData);
                const firstNodeId = nodesData.length > 0 ? Number(nodesData[0].id) : 0;
                setSelectedNode(Number.isInteger(firstNodeId) && firstNodeId > 0 ? firstNodeId : 0);

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
                            const stripeInstance = await loadStripeOnce(stripePublicKey.key);
                            setStripe(stripeInstance);
                        } catch (error) {
                            console.error('Error initializing Stripe:', error);
                        }
                    }

                    // Mollie doesn't need pre-initialization like Stripe
                    // Payment is created when user clicks the button
                }
            } catch (error: unknown) {
                console.error('Error fetching billing order data:', error);
                if (error instanceof Error && error.message) {
                    addFlash({ key: 'account:billing:order', type: 'error', message: error.message });
                    return;
                }
                clearAndAddHttpError({ key: 'account:billing:order', error });
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
        const selectedNodeData = nodes?.find(n => Number(n.id) === selectedNode);
        const selectedEgg = availableEggs.find(e => e.id === selectedEggId);
        const selectedCycle = billingCycles.find(c => c.days === selectedBillingDays);

        const variableValues =
            eggs
                ?.filter(variable => variable.isEditable)
                .map(variable => ({
                    name: variable.name,
                    value: vars.get(variable.envVariable) ?? variable.serverValue ?? variable.defaultValue ?? '',
                })) || [];

        const renderReviewStep = () => (
            <div className={'space-y-6'}>
                <div>
                    <h2 className={'text-3xl font-bold text-gray-100'}>Review and Confirm</h2>
                    <p className={'mt-2 text-gray-400'}>Almost done! Name your server and review your order.</p>
                </div>

                {/* Server Name Section */}
                <div className={'rounded-lg border p-6'} style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}>
                    <h3 className={'mb-4 text-lg font-semibold text-gray-200'}>Server Name</h3>
                    <input
                        id={'server-name-input'}
                        type={'text'}
                        placeholder={'Enter a name for your server'}
                        value={serverName}
                        onChange={e => {
                            setServerName(e.target.value);
                            setServerNameTouched(true);
                        }}
                        required
                        maxLength={191}
                        aria-invalid={serverNameTouched && !serverName.trim()}
                        aria-describedby={serverNameTouched && !serverName.trim() ? 'server-name-error' : undefined}
                        className={classNames(
                            'w-full rounded-lg border-2 px-4 py-3 text-sm transition-all',
                            'text-gray-200 placeholder-gray-500',
                            'focus:outline-none focus:ring-2 focus:ring-primary/20',
                            {
                                'border-gray-600': !serverNameTouched,
                                'border-green-500 focus:border-green-500': serverNameTouched && serverName.trim(),
                                'border-red-500 focus:border-red-500': serverNameTouched && !serverName.trim(),
                            },
                        )}
                        style={{
                            backgroundColor: colors.secondary,
                        }}
                    />
                    {serverNameTouched && !serverName.trim() && (
                        <p id={'server-name-error'} className={'mt-2 text-xs text-red-400'} role={'alert'}>
                            Server name is required to continue
                        </p>
                    )}
                </div>

                {/* Final Summary */}
                <div className={'rounded-lg border p-6'} style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}>
                    <h3 className={'mb-4 text-lg font-semibold text-gray-200'}>Configuration Summary</h3>
                    <div className={'space-y-4'}>
                        <div className={'grid gap-4 sm:grid-cols-2'}>
                            <div className={'rounded-md border border-gray-700 p-4'}>
                                <p className={'text-xs uppercase tracking-wide text-gray-500'}>Location</p>
                                <p className={'mt-1 text-sm font-medium text-gray-200'}>
                                    {selectedNodeData ? selectedNodeData.name : 'Not selected'}
                                </p>
                            </div>
                            <div className={'rounded-md border border-gray-700 p-4'}>
                                <p className={'text-xs uppercase tracking-wide text-gray-500'}>Billing Cycle</p>
                                <p className={'mt-1 text-sm font-medium text-gray-200'}>
                                    {selectedCycle
                                        ? `${selectedCycle.days} ${selectedCycle.days === 1 ? 'day' : 'days'}`
                                        : 'Not selected'}
                                </p>
                            </div>
                            <div className={'rounded-md border border-gray-700 p-4'}>
                                <p className={'text-xs uppercase tracking-wide text-gray-500'}>Server Type</p>
                                <p className={'mt-1 text-sm font-medium text-gray-200'}>
                                    {selectedEgg ? selectedEgg.name : 'Not selected'}
                                </p>
                            </div>
                            <div className={'rounded-md border border-gray-700 p-4'}>
                                <p className={'text-xs uppercase tracking-wide text-gray-500'}>Resources</p>
                                <div className={'mt-2 grid grid-cols-3 gap-2'}>
                                    <div className={'text-center'}>
                                        <FontAwesomeIcon icon={faMicrochip} className={'h-4 w-4 mb-1 text-gray-500'} />
                                        <p className={'text-xs text-gray-500'}>CPU</p>
                                        <p className={'text-sm font-medium text-gray-200'}>{product.limits.cpu}%</p>
                                    </div>
                                    <div className={'text-center'}>
                                        <FontAwesomeIcon icon={faMemory} className={'h-4 w-4 mb-1 text-gray-500'} />
                                        <p className={'text-xs text-gray-500'}>RAM</p>
                                        <p className={'text-sm font-medium text-gray-200'}>
                                            {(product.limits.memory / 1024).toFixed(1)} GB
                                        </p>
                                    </div>
                                    <div className={'text-center'}>
                                        <FontAwesomeIcon icon={faHdd} className={'h-4 w-4 mb-1 text-gray-500'} />
                                        <p className={'text-xs text-gray-500'}>Storage</p>
                                        <p className={'text-sm font-medium text-gray-200'}>
                                            {(product.limits.disk / 1024).toFixed(1)} GB
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className={'rounded-md border border-gray-700 p-4'}>
                            <p className={'text-xs uppercase tracking-wide text-gray-500'}>Variables</p>
                            {variableValues.length > 0 ? (
                                <div className={'mt-3 space-y-2'}>
                                    {variableValues.map(variable => (
                                        <div key={variable.name} className={'flex items-center justify-between text-sm'}>
                                            <span className={'text-gray-400'}>{variable.name}</span>
                                            <span className={'text-gray-200 font-medium'}>
                                                {variable.value && variable.value.toString().trim() !== ''
                                                    ? variable.value
                                                    : 'Not set'}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className={'mt-3 text-sm text-gray-400'}>No additional configuration required.</p>
                            )}
                        </div>
                    </div>
                </div>

                {/* Legal Agreements */}
                <div className={'rounded-lg border p-6'} style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}>
                    <h3 className={'mb-4 text-lg font-semibold text-gray-200'}>Terms & Conditions</h3>
                    <div
                        className={'flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-all'}
                        style={
                            legalAgreed
                                ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` }
                                : { borderColor: '#374151', backgroundColor: colors.secondary }
                        }
                        onClick={() => setLegalAgreed(!legalAgreed)}
                    >
                        <AdminCheckbox name={'legal'} checked={legalAgreed} onChange={() => setLegalAgreed(!legalAgreed)} />
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
            </div>
        );

        switch (currentStep) {
            case 1: // Billing (Location + Cycle)
                return (
                    <div className={'space-y-6'}>
                        <div>
                            <h2 className={'text-3xl font-bold text-gray-100'}>Select Billing</h2>
                            <p className={'mt-2 text-gray-400'}>
                                Start by choosing where your server will live and how often you want to be billed. Pricing updates
                                instantly as you make your selections.
                            </p>
                        </div>
                        <div className={'grid gap-6 lg:grid-cols-2'}>
                            <div className={'space-y-4'}>
                                <div className={'flex items-center justify-between'}>
                                    <h3 className={'text-xl font-semibold text-gray-100'}>Choose Location</h3>
                                    <p className={'text-xs text-gray-500'}>Impacts latency and price</p>
                                </div>
                                {(!nodes || nodes.length < 1) && (
                                    <Alert type={'danger'}>
                                        No nodes are available for this product. Please contact support.
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

                            <div className={'space-y-4'}>
                                <div className={'flex items-center justify-between'}>
                                    <h3 className={'text-xl font-semibold text-gray-100'}>Billing Cycle</h3>
                                    <p className={'text-xs text-gray-500'}>See discounts or premiums</p>
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
                        </div>
                    </div>
                );

            case 2: // Server Type & Variables
                return (
                    <div className={'space-y-6'}>
                        <div>
                            <h2 className={'text-3xl font-bold text-gray-100'}>Select Server Type</h2>
                            <p className={'mt-2 text-gray-400'}>
                                Choose which type of server software you want to run and configure any required variables below.
                            </p>
                        </div>
                        {availableEggs.length === 0 ? (
                            <Alert type={'warning'}>
                                No server software options are currently available for this product. This may be due to
                                configuration changes. Please contact support for assistance.
                            </Alert>
                        ) : (
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
                        )}

                        <div
                            className={'rounded-lg border p-6'}
                            style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}
                        >
                            <div className={'flex items-center justify-between'}>
                                <div>
                                    <h3 className={'text-lg font-semibold text-gray-200'}>Server Configuration</h3>
                                    <p className={'text-sm text-gray-400'}>
                                        Fill out variables required by your selected server type.
                                    </p>
                                </div>
                            </div>
                            <div className={'mt-4 grid gap-4 sm:grid-cols-2'}>
                                {eggs === undefined && selectedEggId !== undefined ? (
                                    <Spinner centered />
                                ) : hasEditableVariables && eggs ? (
                                    eggs
                                        .filter(variable => variable.isEditable)
                                        .map(variable => (
                                            <div key={variable.envVariable}>
                                                <VariableBox
                                                    variable={variable}
                                                    vars={vars}
                                                    onValueChange={() => setVarsUpdateCount(v => v + 1)}
                                                />
                                            </div>
                                        ))
                                ) : (
                                    <p className={'text-sm text-gray-400'}>
                                        No additional configuration is required for this server type.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                );

            case 3:
                return renderReviewStep();

            case 4: // Payment step
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
                                        <Button onClick={createFree} size={Button.Sizes.Large} className={'w-full'}>
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
            default:
                return renderReviewStep();
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

            {/* Main Wizard Content with Two-Column Layout */}
            <div className={'mt-10'}>
                <div className={'mx-auto max-w-7xl'}>
                    <div className={'grid grid-cols-1 lg:grid-cols-3 gap-8'}>
                        {/* Left Column - Main Content */}
                        <div className={'lg:col-span-2'}>{renderStepContent()}</div>

                        {/* Right Column - Sticky Subtotal Card */}
                        <div className={'lg:col-span-1'}>
                            <div className={'sticky top-24'}>
                                <SubtotalCard
                                    basePrice={product.price}
                                    selectedNode={selectedNode}
                                    nodes={nodes}
                                    selectedEggId={selectedEggId}
                                    availableEggs={availableEggs}
                                    selectedBillingDays={selectedBillingDays}
                                    billingCycles={billingCycles}
                                    couponDiscount={couponData?.discount || 0}
                                    couponCode={couponData?.coupon.code}
                                    productName={product.name}
                                     // Keep detailed pricing breakdown visible throughout checkout for transparency.
                                     showDetailedBreakdown
                                     showCouponInput={currentStep === TOTAL_STEPS}
                                    onCouponApplied={handleCouponApplied}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Navigation Buttons */}
            <div className={'mt-8 flex items-center justify-between border-t border-gray-700 pt-6'}>
                <div>
                    {currentStep > 1 && currentStep <= PAYMENT_STEP && (
                        <Button.Text onClick={goToPreviousStep} variant={Button.Variants.Secondary}>
                            ← Back
                        </Button.Text>
                    )}
                </div>
                <div>
                    {currentStep < TOTAL_STEPS && (
                        <Button onClick={goToNextStep} size={Button.Sizes.Large} disabled={!isStepValid(currentStep)}>
                            {currentStep === TOTAL_STEPS - 1 ? 'Continue to Review' : 'Next →'}
                        </Button>
                    )}
                    {currentStep === TOTAL_STEPS && (
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
