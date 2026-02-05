import { useState } from 'react';
import { nanoid } from 'nanoid';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import { useStoreActions, useStoreState } from '@/state/hooks';
import { faCalendar, faDollarSign, faPlus, faTrash } from '@fortawesome/free-solid-svg-icons';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import { updateSettings } from '@/api/routes/admin/billing';
import FlashMessageRender from '@/elements/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import { Alert } from '@/elements/alert';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import tw from 'twin.macro';

const EPSILON = 0.001;

interface MultiplierStep {
    id: string;
    maxDays: number;
    multiplier: number;
}

type StoredMultiplierStep = Omit<MultiplierStep, 'id'>;

const formatPriceAdjustment = (multiplier: number): string => {
    // Use epsilon comparison for floating point
    if (Math.abs(multiplier - 1.0) < EPSILON) return 'Standard price';
    const percentage = Math.round((multiplier - 1) * 100);
    if (percentage > 0) return `+${percentage}%`;
    return `${percentage}%`;
};

const formatBillingLength = (maxDays: number, isLast: boolean): string => {
    if (maxDays === 30) return '30 days (base)';
    if (isLast) return `${maxDays}+ days`;
    return `Up to ${maxDays} days`;
};

export default () => {
    const settings = useStoreState(s => s.everest.data!.billing);
    const updateEverest = useStoreActions(s => s.everest.updateEverest);
    const { clearFlashes, addFlash } = useFlash();

    const [defaultBillingDays, setDefaultBillingDays] = useState<number>(settings.renewal?.default_billing_days || 30);
    
    // Parse multiplier steps from settings or use defaults
    const parseSteps = (stepsString: string | undefined): MultiplierStep[] => {
        if (!stepsString) {
            return [
                { id: nanoid(), maxDays: 10, multiplier: 1.30 },
                { id: nanoid(), maxDays: 20, multiplier: 1.20 },
                { id: nanoid(), maxDays: 29, multiplier: 1.10 },
                { id: nanoid(), maxDays: 30, multiplier: 1.00 },
                { id: nanoid(), maxDays: 59, multiplier: 0.95 },
                { id: nanoid(), maxDays: 89, multiplier: 0.90 },
                { id: nanoid(), maxDays: 999, multiplier: 0.85 },
            ];
        }
        try {
            const parsed = JSON.parse(stepsString) as StoredMultiplierStep[];
            // Add IDs to existing steps if they don't have them
            return parsed.map((step) => ({
                ...step,
                id: nanoid(),
            }));
        } catch {
            return [];
        }
    };

    const [multiplierSteps, setMultiplierSteps] = useState<MultiplierStep[]>(
        parseSteps(settings.renewal?.multiplier_steps as string | undefined)
    );
    const [loading, setLoading] = useState(false);

    const addStep = () => {
        setMultiplierSteps([...multiplierSteps, { id: nanoid(), maxDays: 30, multiplier: 1.0 }]);
    };

    const removeStep = (id: string) => {
        setMultiplierSteps(multiplierSteps.filter(step => step.id !== id));
    };

    const updateStep = (id: string, field: 'maxDays' | 'multiplier', value: number) => {
        const updated = multiplierSteps.map(step =>
            step.id === id ? { ...step, [field]: value } : step
        );
        setMultiplierSteps(updated);
    };

    const handleSaveAll = async () => {
        clearFlashes('admin:billing');
        setLoading(true);

        try {
            // Validate steps
            if (multiplierSteps.length === 0) {
                throw new Error('At least one multiplier step is required');
            }

            // Sort steps by maxDays for consistency
            const sortedSteps = [...multiplierSteps].sort((a, b) => a.maxDays - b.maxDays);
            
            // Remove IDs before saving (backend doesn't need them)
            const stepsToSave = sortedSteps.map(({ id, ...step }) => step);

            // Save both settings
            await updateSettings('renewal:default_billing_days', defaultBillingDays);
            await updateSettings('renewal:multiplier_steps', JSON.stringify(stepsToSave));

            // Update state with all new values
            updateEverest({
                billing: {
                    ...settings,
                    renewal: {
                        ...settings.renewal,
                        default_billing_days: defaultBillingDays,
                        multiplier_steps: JSON.stringify(stepsToSave),
                    },
                },
            });

            addFlash({
                key: 'admin:billing',
                type: 'success',
                message: 'Billing rules updated successfully.',
            });
        } catch (error) {
            console.error(error);
            addFlash({
                key: 'admin:billing',
                type: 'error',
                message: error instanceof Error ? error.message : 'Failed to update billing rules.',
            });
        } finally {
            setLoading(false);
        }
    };

    // Sort steps for display
    const sortedStepsForDisplay = [...multiplierSteps].sort((a, b) => a.maxDays - b.maxDays);

    return (
        <div>
            <FlashMessageRender byKey={'admin:billing'} className={'mb-4'} />

            <Alert type={'info'} className={'mb-4'}>
                <strong>Global Billing Settings:</strong> These settings apply to all products. The price adjustment steps control pricing for different billing cycle lengths across all products.
            </Alert>

            <div className={'grid gap-4 lg:grid-cols-1'}>
                <AdminBox title={'Default Billing Length'} icon={faCalendar}>
                    <p className={'mb-4 text-gray-400'}>
                        The default billing cycle length used for pricing calculations.
                    </p>
                    <div>
                        <Label>Default Billing Days</Label>
                        <Input
                            type={'number'}
                            min={1}
                            max={365}
                            value={defaultBillingDays}
                            onChange={e => setDefaultBillingDays(parseInt(e.target.value) || 30)}
                            disabled={loading}
                        />
                        <p className={'mt-2 text-xs text-gray-500'}>
                            Base reference for billing cycle calculations (typically 30 days).
                        </p>
                    </div>
                </AdminBox>
            </div>

            <div className={'mt-4'}>
                <AdminBox title={'Price Adjustment Steps'} icon={faDollarSign}>
                    <p className={'mb-4 text-gray-400'}>
                        Define tiered pricing adjustments based on billing cycle length. Longer billing cycles typically receive discounts.
                    </p>
                    
                    {/* Price Adjustment Table */}
                    <div css={tw`overflow-x-auto mb-4`}>
                        <table css={tw`w-full border-collapse`}>
                            <thead>
                                <tr css={tw`border-b-2 border-neutral-600`}>
                                    <th css={tw`text-left py-3 px-4 text-neutral-300 font-semibold`}>
                                        Billing Length
                                    </th>
                                    <th css={tw`text-left py-3 px-4 text-neutral-300 font-semibold`}>
                                        Price Adjustment
                                    </th>
                                    <th css={tw`text-right py-3 px-4 text-neutral-300 font-semibold`}>
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {sortedStepsForDisplay.map((step, idx) => (
                                    <tr 
                                        key={step.id} 
                                        css={tw`border-b border-neutral-700 hover:bg-neutral-700 transition-colors`}
                                    >
                                        <td css={tw`py-3 px-4`}>
                                            <div css={tw`flex items-center gap-2`}>
                                                <span css={tw`text-neutral-300 min-w-[150px]`}>
                                                    {formatBillingLength(step.maxDays, idx === sortedStepsForDisplay.length - 1)}
                                                </span>
                                                <Input
                                                    type={'number'}
                                                    min={1}
                                                    max={999}
                                                    value={step.maxDays}
                                                    onChange={e => updateStep(step.id, 'maxDays', parseInt(e.target.value) || 30)}
                                                    disabled={loading}
                                                    css={tw`w-24`}
                                                />
                                            </div>
                                        </td>
                                        <td css={tw`py-3 px-4`}>
                                            <div css={tw`flex items-center gap-2`}>
                                                <span 
                                                    css={[
                                                        tw`min-w-[120px] font-medium`,
                                                        step.multiplier > 1.0 && tw`text-red-400`,
                                                        Math.abs(step.multiplier - 1.0) < EPSILON && tw`text-blue-400`,
                                                        step.multiplier < 1.0 && tw`text-green-400`,
                                                    ]}
                                                >
                                                    {formatPriceAdjustment(step.multiplier)}
                                                </span>
                                                <Input
                                                    type={'number'}
                                                    step={0.01}
                                                    min={0.5}
                                                    max={2.0}
                                                    value={step.multiplier}
                                                    onChange={e => updateStep(step.id, 'multiplier', parseFloat(e.target.value) || 1.0)}
                                                    disabled={loading}
                                                    css={tw`w-24`}
                                                />
                                            </div>
                                        </td>
                                        <td css={tw`py-3 px-4 text-right`}>
                                            <Button
                                                type="button"
                                                onClick={() => removeStep(step.id)}
                                                className="!bg-red-500 hover:!bg-red-600"
                                                disabled={loading || multiplierSteps.length === 1}
                                            >
                                                <FontAwesomeIcon icon={faTrash} />
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div css={tw`mt-4`}>
                        <Button type="button" onClick={addStep} disabled={loading}>
                            <FontAwesomeIcon icon={faPlus} className="mr-2" />
                            Add Step
                        </Button>
                    </div>

                    <Alert type={'info'} className={'mt-4'}>
                        <strong>How it works:</strong> The system finds the first step where days ≤ maxDays and applies that multiplier. For example, a 15-day billing cycle would match the first step with maxDays ≥ 15.
                    </Alert>
                </AdminBox>
            </div>

            <div className={'mt-6 flex justify-end'}>
                <Button onClick={handleSaveAll} disabled={loading}>
                    {loading ? 'Saving...' : 'Save All Settings'}
                </Button>
            </div>
        </div>
    );
};
