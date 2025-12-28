import { useEffect, useState } from 'react';
import TitledGreyBox from '@/elements/TitledGreyBox';
import { ServerContext } from '@/state/server';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExchangeAlt, faTimesCircle, faInfoCircle } from '@fortawesome/free-solid-svg-icons';
import useFlash from '@/plugins/useFlash';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { Alert } from '@/elements/alert';
import { Product } from '@definitions/account/billing';
import { getAvailablePlans, validatePlanChange, changePlan, PlanChangeValidation } from '@/api/routes/server/billing';
import { Button } from '@/elements/button';
import tw from 'twin.macro';
import { useStoreState } from '@/state/hooks';
import { Dialog } from '@/elements/dialog';

export default () => {
    const [plans, setPlans] = useState<Product[]>([]);
    const [loading, setLoading] = useState<boolean>(true);
    const [changing, setChanging] = useState<boolean>(false);
    const [selectedPlan, setSelectedPlan] = useState<Product | null>(null);
    const [validation, setValidation] = useState<PlanChangeValidation | null>(null);
    const [showConfirmDialog, setShowConfirmDialog] = useState<boolean>(false);

    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const settings = useStoreState(s => s.everest.data!.billing);
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
            // Reload the page to reflect the new plan
            window.location.reload();
        } catch (error) {
            clearAndAddHttpError({ key: 'server:billing:plan-change', error });
            setChanging(false);
            setShowConfirmDialog(false);
        }
    };

    const formatResourceViolations = () => {
        if (!validation?.violations) return null;

        return (
            <div css={tw`mt-4 space-y-2`}>
                <p css={tw`text-sm font-semibold text-red-400`}>Current usage exceeds the following limits:</p>
                <ul css={tw`list-disc list-inside space-y-1`}>
                    {Object.entries(validation.violations).map(([resource, data]) => (
                        <li key={resource} css={tw`text-xs text-red-300`}>
                            <strong>{resource.charAt(0).toUpperCase() + resource.slice(1)}:</strong> Using{' '}
                            {data.current} {data.unit}, new limit is {data.limit} {data.unit}
                        </li>
                    ))}
                </ul>
            </div>
        );
    };

    if (!billingProductId) {
        return (
            <TitledGreyBox title={'Change Plan'} icon={faExchangeAlt}>
                <Alert type={'warning'}>
                    This server is not associated with a billing plan, so plan changes are not available.
                </Alert>
            </TitledGreyBox>
        );
    }

    return (
        <>
            <TitledGreyBox title={'Change Plan'} icon={faExchangeAlt}>
                <SpinnerOverlay visible={loading} />
                {!loading && plans.length === 0 && (
                    <Alert type={'info'}>
                        <FontAwesomeIcon icon={faInfoCircle} css={tw`mr-2`} />
                        No other plans are available in your current category.
                    </Alert>
                )}

                {!loading && plans.length > 0 && (
                    <div>
                        <p css={tw`text-gray-400 text-xs mb-4`}>
                            Upgrade or downgrade your server to a different plan within the same category.
                        </p>

                        <div css={tw`space-y-3`}>
                            {plans.map(plan => (
                                <div
                                    key={plan.id}
                                    css={tw`bg-gray-700 rounded-lg p-4 border border-gray-600 hover:border-gray-500 transition-colors`}
                                >
                                    <div css={tw`flex justify-between items-start mb-2`}>
                                        <div css={tw`flex-1`}>
                                            <h4 css={tw`text-gray-200 font-semibold text-sm`}>{plan.name}</h4>
                                            {plan.description && (
                                                <p css={tw`text-gray-400 text-xs mt-1`}>{plan.description}</p>
                                            )}
                                        </div>
                                        <div css={tw`text-right ml-4`}>
                                            <p css={tw`text-lg font-bold text-gray-200`}>
                                                {settings.currency.symbol}
                                                {plan.price}
                                            </p>
                                            <p css={tw`text-xs text-gray-400`}>
                                                {settings.currency.code.toUpperCase()}
                                            </p>
                                        </div>
                                    </div>

                                    <div css={tw`grid grid-cols-2 md:grid-cols-3 gap-2 mb-3 text-xs`}>
                                        <div>
                                            <span css={tw`text-gray-500`}>CPU:</span>
                                            <span css={tw`text-gray-300 ml-1`}>{plan.limits.cpu}%</span>
                                        </div>
                                        <div>
                                            <span css={tw`text-gray-500`}>RAM:</span>
                                            <span css={tw`text-gray-300 ml-1`}>{plan.limits.memory} MB</span>
                                        </div>
                                        <div>
                                            <span css={tw`text-gray-500`}>Disk:</span>
                                            <span css={tw`text-gray-300 ml-1`}>{plan.limits.disk} MB</span>
                                        </div>
                                        <div>
                                            <span css={tw`text-gray-500`}>Databases:</span>
                                            <span css={tw`text-gray-300 ml-1`}>{plan.limits.database}</span>
                                        </div>
                                        <div>
                                            <span css={tw`text-gray-500`}>Backups:</span>
                                            <span css={tw`text-gray-300 ml-1`}>{plan.limits.backup}</span>
                                        </div>
                                        <div>
                                            <span css={tw`text-gray-500`}>Allocations:</span>
                                            <span css={tw`text-gray-300 ml-1`}>{plan.limits.allocation}</span>
                                        </div>
                                    </div>

                                    <Button
                                        onClick={() => handlePlanSelect(plan)}
                                        disabled={changing}
                                        size="sm"
                                        css={tw`w-full`}
                                    >
                                        Select This Plan
                                    </Button>

                                    {selectedPlan?.id === plan.id && validation && !validation.valid && (
                                        <Alert type={'danger'} className={'mt-3'}>
                                            <div css={tw`flex items-start`}>
                                                <FontAwesomeIcon icon={faTimesCircle} css={tw`mr-2 mt-1`} />
                                                <div css={tw`flex-1`}>
                                                    <p css={tw`text-sm font-semibold`}>Cannot downgrade to this plan</p>
                                                    {formatResourceViolations()}
                                                </div>
                                            </div>
                                        </Alert>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </TitledGreyBox>

            {/* Confirmation Dialog */}
            <Dialog
                open={showConfirmDialog}
                onClose={() => setShowConfirmDialog(false)}
                title="Confirm Plan Change"
                description={selectedPlan ? `Are you sure you want to change to the ${selectedPlan.name} plan?` : ''}
            >
                {selectedPlan && (
                    <>
                        <div css={tw`mt-4 bg-gray-700 rounded-lg p-4`}>
                            <p css={tw`text-sm font-semibold text-gray-200 mb-2`}>New Plan Resources:</p>
                            <div css={tw`grid grid-cols-2 gap-2 text-xs`}>
                                <div>
                                    <span css={tw`text-gray-500`}>CPU:</span>
                                    <span css={tw`text-gray-300 ml-1`}>{selectedPlan.limits.cpu}%</span>
                                </div>
                                <div>
                                    <span css={tw`text-gray-500`}>RAM:</span>
                                    <span css={tw`text-gray-300 ml-1`}>{selectedPlan.limits.memory} MB</span>
                                </div>
                                <div>
                                    <span css={tw`text-gray-500`}>Disk:</span>
                                    <span css={tw`text-gray-300 ml-1`}>{selectedPlan.limits.disk} MB</span>
                                </div>
                                <div>
                                    <span css={tw`text-gray-500`}>Databases:</span>
                                    <span css={tw`text-gray-300 ml-1`}>{selectedPlan.limits.database}</span>
                                </div>
                                <div>
                                    <span css={tw`text-gray-500`}>Backups:</span>
                                    <span css={tw`text-gray-300 ml-1`}>{selectedPlan.limits.backup}</span>
                                </div>
                                <div>
                                    <span css={tw`text-gray-500`}>Allocations:</span>
                                    <span css={tw`text-gray-300 ml-1`}>{selectedPlan.limits.allocation}</span>
                                </div>
                            </div>
                            <div css={tw`mt-3 pt-3 border-t border-gray-600`}>
                                <p css={tw`text-sm`}>
                                    <span css={tw`text-gray-500`}>Price:</span>
                                    <span css={tw`text-gray-200 font-bold ml-2`}>
                                        {settings.currency.symbol}
                                        {selectedPlan.price} {settings.currency.code.toUpperCase()}
                                    </span>
                                </p>
                            </div>
                        </div>
                        <p css={tw`text-xs text-yellow-400 mt-4`}>
                            <FontAwesomeIcon icon={faInfoCircle} css={tw`mr-1`} />
                            Your server will be updated with the new resource limits immediately. The page will reload
                            after the change is complete.
                        </p>
                    </>
                )}
                <Dialog.Footer>
                    <Button.Text onClick={() => setShowConfirmDialog(false)} disabled={changing}>
                        Cancel
                    </Button.Text>
                    <Button onClick={handleConfirmChange} disabled={changing}>
                        {changing ? 'Changing Plan...' : 'Confirm Change'}
                    </Button>
                </Dialog.Footer>
            </Dialog>
        </>
    );
};
