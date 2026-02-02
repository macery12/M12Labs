import { useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import { useStoreActions, useStoreState } from '@/state/hooks';
import { faCalendar, faClock, faDollarSign, faPlus, faTrash } from '@fortawesome/free-solid-svg-icons';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import { updateSettings } from '@/api/routes/admin/billing';
import FlashMessageRender from '@/elements/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import { Alert } from '@/elements/alert';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import tw from 'twin.macro';

interface MultiplierStep {
    maxDays: number;
    multiplier: number;
}

export default () => {
    const settings = useStoreState(s => s.everest.data!.billing);
    const updateEverest = useStoreActions(s => s.everest.updateEverest);
    const { clearFlashes, addFlash } = useFlash();

    const [paidRenewalDays, setPaidRenewalDays] = useState<number>(settings.renewal?.days || 30);
    const [freeRenewalDays, setFreeRenewalDays] = useState<number>(settings.renewal?.free_renewal_days || 30);
    const [freeGraceDays, setFreeGraceDays] = useState<number>(settings.renewal?.free_suspension_days || 7);
    const [paidGraceDays, setPaidGraceDays] = useState<number>(settings.renewal?.paid_suspension_days || 30);
    const [defaultBillingDays, setDefaultBillingDays] = useState<number>(settings.renewal?.default_billing_days || 30);
    
    // Parse multiplier steps from settings or use defaults
    const parseSteps = (stepsString: string | undefined): MultiplierStep[] => {
        if (!stepsString) {
            return [
                { maxDays: 10, multiplier: 1.30 },
                { maxDays: 20, multiplier: 1.20 },
                { maxDays: 29, multiplier: 1.10 },
                { maxDays: 30, multiplier: 1.00 },
                { maxDays: 59, multiplier: 0.95 },
                { maxDays: 89, multiplier: 0.90 },
                { maxDays: 999, multiplier: 0.85 },
            ];
        }
        try {
            return JSON.parse(stepsString);
        } catch {
            return [];
        }
    };

    const [multiplierSteps, setMultiplierSteps] = useState<MultiplierStep[]>(
        parseSteps(settings.renewal?.multiplier_steps as string | undefined)
    );
    const [loading, setLoading] = useState(false);

    const addStep = () => {
        setMultiplierSteps([...multiplierSteps, { maxDays: 30, multiplier: 1.0 }]);
    };

    const removeStep = (index: number) => {
        setMultiplierSteps(multiplierSteps.filter((_, i) => i !== index));
    };

    const updateStep = (index: number, field: 'maxDays' | 'multiplier', value: number) => {
        const updated = [...multiplierSteps];
        updated[index] = { ...updated[index], [field]: value };
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

            // Save all settings in sequence
            await updateSettings('renewal:days', paidRenewalDays);
            await updateSettings('renewal:free_renewal_days', freeRenewalDays);
            await updateSettings('renewal:free_suspension_days', freeGraceDays);
            await updateSettings('renewal:paid_suspension_days', paidGraceDays);
            await updateSettings('renewal:default_billing_days', defaultBillingDays);
            await updateSettings('renewal:multiplier_steps', JSON.stringify(sortedSteps));

            // Update state with all new values
            updateEverest({
                billing: {
                    ...settings,
                    renewal: {
                        ...settings.renewal,
                        days: paidRenewalDays,
                        free_renewal_days: freeRenewalDays,
                        free_suspension_days: freeGraceDays,
                        paid_suspension_days: paidGraceDays,
                        default_billing_days: defaultBillingDays,
                        multiplier_steps: JSON.stringify(sortedSteps),
                    },
                },
            });

            addFlash({
                key: 'admin:billing',
                type: 'success',
                message: 'Renewal settings updated successfully.',
            });
        } catch (error) {
            console.error(error);
            addFlash({
                key: 'admin:billing',
                type: 'error',
                message: error instanceof Error ? error.message : 'Failed to update renewal settings.',
            });
        } finally {
            setLoading(false);
        }
    };

    return (
        <div>
            <FlashMessageRender byKey={'admin:billing'} className={'mb-4'} />

            <Alert type={'info'} className={'mb-4'}>
                <strong>Global Billing Settings:</strong> These settings apply to all products. The multiplier steps control pricing for different billing cycle lengths across all products.
            </Alert>

            <div className={'grid gap-4 lg:grid-cols-2'}>
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

                <AdminBox title={'Paid Renewal Period (Days)'} icon={faCalendar}>
                    <p className={'mb-4 text-gray-400'}>
                        Number of days a paid server subscription lasts when purchased or renewed.
                    </p>
                    <div>
                        <Label>Days</Label>
                        <Input
                            type={'number'}
                            min={1}
                            max={365}
                            value={paidRenewalDays}
                            onChange={e => setPaidRenewalDays(parseInt(e.target.value) || 30)}
                            disabled={loading}
                        />
                        <p className={'mt-2 text-xs text-gray-500'}>
                            When a paid server is purchased or renewed, it will be active for this many days.
                        </p>
                    </div>
                </AdminBox>

                <AdminBox title={'Paid Grace Period (Days)'} icon={faClock}>
                    <p className={'mb-4 text-gray-400'}>
                        Number of days after expiration before a paid server is automatically suspended.
                    </p>
                    <div>
                        <Label>Days</Label>
                        <Input
                            type={'number'}
                            min={0}
                            max={90}
                            value={paidGraceDays}
                            onChange={e => setPaidGraceDays(parseInt(e.target.value) || 30)}
                            disabled={loading}
                        />
                        <p className={'mt-2 text-xs text-gray-500'}>
                            Paid servers will be suspended this many days after their renewal date passes.
                        </p>
                    </div>
                </AdminBox>

                <AdminBox title={'Free Renewal Period (Days)'} icon={faCalendar}>
                    <p className={'mb-4 text-gray-400'}>
                        Number of days a free server subscription lasts when created or renewed.
                    </p>
                    <div>
                        <Label>Days</Label>
                        <Input
                            type={'number'}
                            min={1}
                            max={365}
                            value={freeRenewalDays}
                            onChange={e => setFreeRenewalDays(parseInt(e.target.value) || 30)}
                            disabled={loading}
                        />
                        <p className={'mt-2 text-xs text-gray-500'}>
                            When a free server is created or renewed, it will be active for this many days.
                        </p>
                    </div>
                </AdminBox>

                <AdminBox title={'Free Grace Period (Days)'} icon={faClock}>
                    <p className={'mb-4 text-gray-400'}>
                        Number of days after expiration before a free server is automatically suspended. Free servers
                        can only be renewed before this grace period expires.
                    </p>
                    <div>
                        <Label>Days</Label>
                        <Input
                            type={'number'}
                            min={0}
                            max={90}
                            value={freeGraceDays}
                            onChange={e => setFreeGraceDays(parseInt(e.target.value) || 7)}
                            disabled={loading}
                        />
                        <p className={'mt-2 text-xs text-gray-500'}>
                            Free servers will be suspended this many days after their renewal date passes. Self-service
                            renewal is only available during this grace period.
                        </p>
                    </div>
                </AdminBox>
            </div>

            <div className={'mt-4'}>
                <AdminBox title={'Price Multiplier Steps'} icon={faDollarSign}>
                    <p className={'mb-4 text-gray-400'}>
                        Define tiered pricing multipliers based on billing cycle length. The first matching range is applied.
                    </p>
                    <Alert type={'info'} className={'mb-4'}>
                        <strong>How it works:</strong> For each billing cycle, the system finds the first step where days ≤ maxDays and applies that multiplier. Example: 15 days would match the first step with maxDays ≥ 15.
                    </Alert>
                    
                    <div css={tw`space-y-2`}>
                        {multiplierSteps.map((step, index) => (
                            <div key={index} css={tw`flex items-center gap-3 p-3 rounded bg-neutral-700`}>
                                <div css={tw`flex-1 flex gap-3`}>
                                    <div css={tw`flex-1`}>
                                        <Label>Max Days</Label>
                                        <Input
                                            type={'number'}
                                            min={1}
                                            max={999}
                                            value={step.maxDays}
                                            onChange={e => updateStep(index, 'maxDays', parseInt(e.target.value) || 30)}
                                            disabled={loading}
                                        />
                                    </div>
                                    <div css={tw`flex-1`}>
                                        <Label>Multiplier</Label>
                                        <Input
                                            type={'number'}
                                            step={0.01}
                                            min={0.5}
                                            max={2.0}
                                            value={step.multiplier}
                                            onChange={e => updateStep(index, 'multiplier', parseFloat(e.target.value) || 1.0)}
                                            disabled={loading}
                                        />
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    onClick={() => removeStep(index)}
                                    className="!bg-red-500 hover:!bg-red-600 mt-6"
                                    disabled={loading || multiplierSteps.length === 1}
                                >
                                    <FontAwesomeIcon icon={faTrash} />
                                </Button>
                            </div>
                        ))}
                    </div>

                    <div css={tw`mt-4`}>
                        <Button type="button" onClick={addStep} disabled={loading}>
                            <FontAwesomeIcon icon={faPlus} className="mr-2" />
                            Add Step
                        </Button>
                    </div>

                    <Alert type={'warning'} className={'mt-4'}>
                        <strong>Example Configuration:</strong>
                        <ul css={tw`list-disc list-inside mt-2 text-sm`}>
                            <li>Days ≤ 10: Multiplier 1.30 (30% premium for very short cycles)</li>
                            <li>Days ≤ 20: Multiplier 1.20 (20% premium)</li>
                            <li>Days ≤ 29: Multiplier 1.10 (10% premium)</li>
                            <li>Days ≤ 30: Multiplier 1.00 (base price)</li>
                            <li>Days ≤ 59: Multiplier 0.95 (5% discount)</li>
                            <li>Days ≤ 89: Multiplier 0.90 (10% discount)</li>
                            <li>Days ≥ 90: Multiplier 0.85 (15% discount for long cycles)</li>
                        </ul>
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
