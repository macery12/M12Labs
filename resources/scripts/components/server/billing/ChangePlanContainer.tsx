import { useEffect, useState } from 'react';
import TitledGreyBox from '@/elements/TitledGreyBox';
import { ServerContext } from '@/state/server';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExchangeAlt, faInfoCircle, faCheckCircle } from '@fortawesome/free-solid-svg-icons';
import useFlash from '@/plugins/useFlash';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { Alert } from '@/elements/alert';
import { Product } from '@definitions/account/billing';
import { getAvailablePlans, validatePlanChange, changePlan, PlanChangeValidation } from '@/api/routes/server/billing';
import { Button } from '@/elements/button';
import tw from 'twin.macro';
import { useStoreState } from '@/state/hooks';
import { Dialog } from '@/elements/dialog';
import Label from '@/elements/Label';

export default () => {
    const [plans, setPlans] = useState<Product[]>([]);
    const [loading, setLoading] = useState<boolean>(true);
    const [changing, setChanging] = useState<boolean>(false);
    const [selectedPlan, setSelectedPlan] = useState<Product | null>(null);
    const [validation, setValidation] = useState<PlanChangeValidation | null>(null);
    const [showConfirmDialog, setShowConfirmDialog] = useState<boolean>(false);

    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const settings = useStoreState(s => s.everest.data!.billing);
    const { colors } = useStoreState(s => s.theme.data!);
    const serverUuid = ServerContext.useStoreState(s => s.server.data!.uuid);
    const billingProductId = ServerContext.useStoreState(s => s.server.data!.billingProductId);
    
    // Use default billing cycle for plan pricing
    const currentBillingDays = settings.renewal?.default_billing_days || 30;
    
    // Get multiplier steps from settings
    const getMultiplierSteps = () => {
        const stepsString = settings.renewal?.multiplier_steps;
        if (!stepsString) return [];
        try {
            if (typeof stepsString === 'string') {
                return JSON.parse(stepsString);
            }
            return stepsString;
        } catch {
            return [];
        }
    };
    
    const multiplierSteps = getMultiplierSteps();

    // Calculate price for a plan based on current billing cycle
    const calculatePlanPrice = (plan: Product): { price: number; discount: number } => {
        const basePrice = plan.basePrice || plan.price;
        const perDayPrice = basePrice / currentBillingDays;
        
        // Find matching multiplier step
        let multiplier = 1.0;
        if (multiplierSteps.length > 0) {
            const sortedSteps = [...multiplierSteps].sort((a: any, b: any) => a.maxDays - b.maxDays);
            for (const step of sortedSteps) {
                if (currentBillingDays <= step.maxDays) {
                    multiplier = step.multiplier;
                    break;
                }
            }
            // If no match found, use last step's multiplier
            if (multiplier === 1.0) {
                multiplier = sortedSteps[sortedSteps.length - 1]?.multiplier || 1.0;
            }
        }
        
        const finalPrice = perDayPrice * currentBillingDays * multiplier;
        const standardPrice = perDayPrice * currentBillingDays;
        const discountPercent = standardPrice > 0 ? ((standardPrice - finalPrice) / standardPrice) * 100 : 0;
        
        return {
            price: Math.round(finalPrice * 100) / 100,
            discount: Math.round(discountPercent * 10) / 10,
        };
    };

    useEffect(() => {
        clearFlashes('server:billing:plan-change');

        if (billingProductId) {
            getAvailablePlans(serverUuid)
                .then(data => setPlans(data))
                .then(() => setLoading(false))
                .catch(error => {
                    setLoading(false);
                    clearAndAddHttpError({ key: 'server:billing:plan-change', error });
                });
        } else {
            setLoading(false);
        }
    }, []);

    const handlePlanSelect = async (plan: Product) => {
        setSelectedPlan(plan);
        setValidation(null);
        clearFlashes('server:billing:plan-change');

        try {
            const result = await validatePlanChange(serverUuid, plan.id);
            setValidation(result);

            if (result.valid) {
                setShowConfirmDialog(true);
            }
        } catch (error) {
            clearAndAddHttpError({ key: 'server:billing:plan-change', error });
        }
    };

    const handleConfirmChange = async () => {
        if (!selectedPlan) return;

        setChanging(true);
        clearFlashes('server:billing:plan-change');

        try {
            await changePlan(serverUuid, selectedPlan.id);
            // Reload the page to reflect the new plan and resource limits
            // This ensures the server context and all components are refreshed
            window.location.reload();
        } catch (error) {
            clearAndAddHttpError({ key: 'server:billing:plan-change', error });
            setChanging(false);
            setShowConfirmDialog(false);
        }
    };

    if (!billingProductId) {
        return null;
    }

    if (loading) {
        return (
            <TitledGreyBox title={'Available Plans'} icon={faExchangeAlt}>
                <SpinnerOverlay visible={loading} />
                <p css={tw`text-gray-400 text-sm`}>Loading available plans...</p>
            </TitledGreyBox>
        );
    }

    if (plans.length === 0) {
        return null;
    }

    return (
        <>
            <TitledGreyBox title={'Available Plans'} icon={faExchangeAlt}>
                <div>
                    <p css={tw`text-gray-400 text-xs mb-2`}>Upgrade or downgrade to a different plan.</p>

                    <div css={tw`space-y-1.5`}>
                        {plans.map(plan => {
                            const { price, discount } = calculatePlanPrice(plan);
                            
                            return (
                                <div key={plan.id} css={tw`relative`}>
                                    <div
                                        css={tw`flex items-center justify-between p-2.5 rounded transition-colors border border-transparent hover:border-gray-500`}
                                        style={{ backgroundColor: colors.secondary }}
                                    >
                                        <div css={tw`flex-1 min-w-0 mr-3`}>
                                            <div css={tw`flex items-baseline gap-2 mb-0.5`}>
                                                <h4 css={tw`text-sm font-medium text-gray-200`}>{plan.name}</h4>
                                <span css={tw`text-xs font-semibold text-gray-300`}>
                                                    {settings.currency.symbol}
                                                    {price.toFixed(2)}
                                                </span>
                                                {discount !== 0 && (
                                                    <span css={tw`text-xs text-green-400`}>
                                                        ({Math.abs(discount).toFixed(1)}% {discount > 0 ? 'discount' : 'premium'})
                                                    </span>
                                                )}
                                            </div>
                                            <div css={tw`flex flex-wrap gap-x-2.5 gap-y-0.5 text-xs text-gray-400`}>
                                                <span>{plan.limits.cpu}% CPU</span>
                                                <span>{plan.limits.memory} MB RAM</span>
                                                <span>{plan.limits.disk} MB Disk</span>
                                                <span>{plan.limits.database} DB</span>
                                                <span>{plan.limits.backup} Backups</span>
                                            </div>
                                        </div>
                                        <Button onClick={() => handlePlanSelect(plan)} disabled={changing} size="sm">
                                            Select
                                        </Button>
                                    </div>
                                    {selectedPlan?.id === plan.id && validation && !validation.valid && (
                                        <Alert type={'danger'} className={'mt-1.5'}>
                                            <p css={tw`text-xs font-medium mb-0.5`}>
                                                Cannot downgrade - usage exceeds limits:
                                            </p>
                                            <ul css={tw`text-xs space-y-0.5 ml-3`}>
                                                {Object.entries(validation.violations || {}).map(([resource, data]) => (
                                                    <li key={resource}>
                                                        {resource}: {data.current} {data.unit} → {data.limit} {data.unit}
                                                    </li>
                                                ))}
                                            </ul>
                                        </Alert>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </TitledGreyBox>

            {/* Confirmation Dialog */}
            <Dialog
                open={showConfirmDialog}
                onClose={() => setShowConfirmDialog(false)}
                title="Confirm Plan Change"
                description={selectedPlan ? `Change to ${selectedPlan.name}?` : ''}
            >
                {selectedPlan && (() => {
                    const { price, discount } = calculatePlanPrice(selectedPlan);
                    
                    return (
                        <>
                            <div css={tw`space-y-2`}>
                                <div>
                                    <Label>New Resources</Label>
                                    <div css={tw`text-sm text-gray-300 space-y-1`}>
                                        <div css={tw`flex justify-between`}>
                                            <span>CPU:</span>
                                            <span>{selectedPlan.limits.cpu}%</span>
                                        </div>
                                        <div css={tw`flex justify-between`}>
                                            <span>RAM:</span>
                                            <span>{selectedPlan.limits.memory} MB</span>
                                        </div>
                                        <div css={tw`flex justify-between`}>
                                            <span>Disk:</span>
                                            <span>{selectedPlan.limits.disk} MB</span>
                                        </div>
                                        <div css={tw`flex justify-between`}>
                                            <span>Databases:</span>
                                            <span>{selectedPlan.limits.database}</span>
                                        </div>
                                        <div css={tw`flex justify-between`}>
                                            <span>Backups:</span>
                                            <span>{selectedPlan.limits.backup}</span>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <Label>Price</Label>
                                    <p css={tw`text-sm text-gray-300`}>
                                        {settings.currency.symbol}
                                        {price.toFixed(2)} {settings.currency.code.toUpperCase()} per month
                                        {discount !== 0 && (
                                            <span css={tw`ml-2 text-green-400 text-xs`}>
                                                ({Math.abs(discount).toFixed(1)}% {discount > 0 ? 'discount' : 'premium'})
                                            </span>
                                        )}
                                    </p>
                                </div>
                            </div>
                            <Alert type={'info'} className={'mt-4'}>
                                <p css={tw`text-xs`}>
                                    Resources will be updated immediately. The page will reload after the change.
                                </p>
                            </Alert>
                        </>
                    );
                })()}
                <Dialog.Footer>
                    <Button.Text onClick={() => setShowConfirmDialog(false)} disabled={changing}>
                        Cancel
                    </Button.Text>
                    <Button onClick={handleConfirmChange} disabled={changing}>
                        {changing ? 'Changing...' : 'Confirm'}
                    </Button>
                </Dialog.Footer>
            </Dialog>
        </>
    );
};
