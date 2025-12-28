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
                    <p css={tw`text-gray-400 text-xs mb-2`}>
                        Upgrade or downgrade to a different plan.
                    </p>

                    <div css={tw`space-y-1.5`}>
                        {plans.map(plan => (
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
                                                {plan.price}
                                            </span>
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
                                        <p css={tw`text-xs font-medium mb-0.5`}>Cannot downgrade - usage exceeds limits:</p>
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
                        ))}
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
                {selectedPlan && (
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
                                    {selectedPlan.price} {settings.currency.code.toUpperCase()} per billing cycle
                                </p>
                            </div>
                        </div>
                        <Alert type={'info'} className={'mt-4'}>
                            <p css={tw`text-xs`}>
                                Resources will be updated immediately. When downgrading, your renewal date will be adjusted proportionally to prevent abuse.
                            </p>
                        </Alert>
                    </>
                )}
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
