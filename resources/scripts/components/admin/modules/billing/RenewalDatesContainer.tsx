import { useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import { useStoreActions, useStoreState } from '@/state/hooks';
import { faCalendar, faClock, faDollarSign } from '@fortawesome/free-solid-svg-icons';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import { updateSettings } from '@/api/routes/admin/billing';
import FlashMessageRender from '@/elements/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import { Alert } from '@/elements/alert';

export default () => {
    const settings = useStoreState(s => s.everest.data!.billing);
    const updateEverest = useStoreActions(s => s.everest.updateEverest);
    const { clearFlashes, addFlash } = useFlash();

    const [paidRenewalDays, setPaidRenewalDays] = useState<number>(settings.renewal?.days || 30);
    const [freeRenewalDays, setFreeRenewalDays] = useState<number>(settings.renewal?.free_renewal_days || 30);
    const [freeGraceDays, setFreeGraceDays] = useState<number>(settings.renewal?.free_suspension_days || 7);
    const [paidGraceDays, setPaidGraceDays] = useState<number>(settings.renewal?.paid_suspension_days || 30);
    const [multiplierUp, setMultiplierUp] = useState<number>(settings.renewal?.multiplier_up || 0.85);
    const [multiplierDown, setMultiplierDown] = useState<number>(settings.renewal?.multiplier_down || 1.25);
    const [loading, setLoading] = useState(false);

    const handleSaveAll = async () => {
        clearFlashes('admin:billing');
        setLoading(true);

        try {
            // Validate multipliers
            if (multiplierUp < 0.5 || multiplierUp > 1.0) {
                throw new Error('Multiplier Up must be between 0.5 and 1.0');
            }
            if (multiplierDown < 1.0 || multiplierDown > 2.0) {
                throw new Error('Multiplier Down must be between 1.0 and 2.0');
            }

            // Save all settings in sequence
            await updateSettings('renewal:days', paidRenewalDays);
            await updateSettings('renewal:free_renewal_days', freeRenewalDays);
            await updateSettings('renewal:free_suspension_days', freeGraceDays);
            await updateSettings('renewal:paid_suspension_days', paidGraceDays);
            await updateSettings('renewal:multiplier_up', multiplierUp);
            await updateSettings('renewal:multiplier_down', multiplierDown);

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
                        multiplier_up: multiplierUp,
                        multiplier_down: multiplierDown,
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
                <strong>Global Billing Settings:</strong> These settings apply to all products. The multipliers control pricing for different billing cycle lengths across all products.
            </Alert>

            <div className={'grid gap-4 lg:grid-cols-2'}>
                <AdminBox title={'Global Price Multipliers'} icon={faDollarSign}>
                    <p className={'mb-4 text-gray-400'}>
                        Default pricing multipliers applied to all products for different billing cycle lengths.
                    </p>
                    <div className={'mb-4'}>
                        <Label>Multiplier Up (for cycles &gt;30 days)</Label>
                        <Input
                            type={'number'}
                            step={0.01}
                            min={0.5}
                            max={1.0}
                            value={multiplierUp}
                            onChange={e => setMultiplierUp(parseFloat(e.target.value) || 0.85)}
                            disabled={loading}
                        />
                        <p className={'mt-2 text-xs text-gray-500'}>
                            Discount for longer billing cycles (e.g., 0.85 = 15% discount). Range: 0.5 to 1.0
                        </p>
                    </div>
                    <div>
                        <Label>Multiplier Down (for cycles &lt;30 days)</Label>
                        <Input
                            type={'number'}
                            step={0.01}
                            min={1.0}
                            max={2.0}
                            value={multiplierDown}
                            onChange={e => setMultiplierDown(parseFloat(e.target.value) || 1.25)}
                            disabled={loading}
                        />
                        <p className={'mt-2 text-xs text-gray-500'}>
                            Premium for shorter billing cycles (e.g., 1.25 = 25% premium). Range: 1.0 to 2.0
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

            <div className={'mt-6 flex justify-end'}>
                <Button onClick={handleSaveAll} disabled={loading}>
                    {loading ? 'Saving...' : 'Save All Settings'}
                </Button>
            </div>
        </div>
    );
};
