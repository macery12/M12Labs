import { useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import { useStoreActions, useStoreState } from '@/state/hooks';
import { faCalendar, faClock } from '@fortawesome/free-solid-svg-icons';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import { updateSettings } from '@/api/routes/admin/billing';
import FlashMessageRender from '@/elements/FlashMessageRender';
import useFlash from '@/plugins/useFlash';

export default () => {
    const settings = useStoreState(s => s.everest.data!.billing);
    const updateEverest = useStoreActions(s => s.everest.updateEverest);
    const { clearFlashes, addFlash } = useFlash();

    const [renewalDays, setRenewalDays] = useState<number>(settings.renewal?.days || 30);
    const [suspensionThreshold, setSuspensionThreshold] = useState<number>(
        settings.renewal?.suspension_threshold || 7
    );
    const [loading, setLoading] = useState(false);

    const handleSaveRenewalDays = async () => {
        clearFlashes('admin:billing');
        setLoading(true);

        try {
            await updateSettings('renewal:days', renewalDays);
            updateEverest({
                billing: {
                    ...settings,
                    renewal: {
                        ...settings.renewal,
                        days: renewalDays,
                    },
                },
            });
            addFlash({
                key: 'admin:billing',
                type: 'success',
                message: 'Renewal days updated successfully.',
            });
        } catch (error) {
            console.error(error);
            addFlash({
                key: 'admin:billing',
                type: 'error',
                message: 'Failed to update renewal days.',
            });
        } finally {
            setLoading(false);
        }
    };

    const handleSaveSuspensionThreshold = async () => {
        clearFlashes('admin:billing');
        setLoading(true);

        try {
            await updateSettings('renewal:suspension_threshold', suspensionThreshold);
            updateEverest({
                billing: {
                    ...settings,
                    renewal: {
                        ...settings.renewal,
                        suspension_threshold: suspensionThreshold,
                    },
                },
            });
            addFlash({
                key: 'admin:billing',
                type: 'success',
                message: 'Suspension threshold updated successfully.',
            });
        } catch (error) {
            console.error(error);
            addFlash({
                key: 'admin:billing',
                type: 'error',
                message: 'Failed to update suspension threshold.',
            });
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className={'grid lg:grid-cols-2 gap-4'}>
            <FlashMessageRender byKey={'admin:billing'} className={'mb-4 col-span-2'} />

            <AdminBox title={'Renewal Period'} icon={faCalendar}>
                <p className={'text-gray-400 mb-4'}>
                    Configure how many days a server is renewed for when a user purchases or renews a server. This
                    applies to both free and paid servers.
                </p>
                <div className={'mb-4'}>
                    <Label>Days per Renewal Period</Label>
                    <Input
                        type={'number'}
                        min={1}
                        max={365}
                        value={renewalDays}
                        onChange={e => setRenewalDays(parseInt(e.target.value) || 30)}
                        disabled={loading}
                    />
                    <p className={'text-xs text-gray-500 mt-2'}>
                        Current value: {settings.renewal?.days || 30} days. When a server is renewed, it will be
                        extended by this many days.
                    </p>
                </div>
                <div className={'text-right'}>
                    <Button onClick={handleSaveRenewalDays} disabled={loading}>
                        {loading ? 'Saving...' : 'Save Renewal Days'}
                    </Button>
                </div>
            </AdminBox>

            <AdminBox title={'Renewal Eligibility Threshold'} icon={faClock}>
                <p className={'text-gray-400 mb-4'}>
                    Configure how many days before expiration users can renew their free servers. Users with paid
                    servers can renew at any time, but free servers can only be renewed when this threshold is met.
                </p>
                <div className={'mb-4'}>
                    <Label>Days Remaining Threshold</Label>
                    <Input
                        type={'number'}
                        min={0}
                        max={30}
                        value={suspensionThreshold}
                        onChange={e => setSuspensionThreshold(parseInt(e.target.value) || 7)}
                        disabled={loading}
                    />
                    <p className={'text-xs text-gray-500 mt-2'}>
                        Current value: {settings.renewal?.suspension_threshold || 7} days. Free servers can be renewed
                        when there are this many days or fewer remaining until expiration.
                    </p>
                </div>
                <div className={'text-right'}>
                    <Button onClick={handleSaveSuspensionThreshold} disabled={loading}>
                        {loading ? 'Saving...' : 'Save Threshold'}
                    </Button>
                </div>
            </AdminBox>

            <div className={'col-span-2'}>
                <AdminBox title={'Information'} icon={faCalendar}>
                    <div className={'text-gray-400 space-y-2'}>
                        <p>
                            <strong className={'text-gray-300'}>Renewal Period:</strong> When a server is purchased or
                            renewed, its expiration date will be set to {renewalDays} days from the renewal time.
                        </p>
                        <p>
                            <strong className={'text-gray-300'}>Suspension:</strong> Servers are automatically
                            suspended by the billing system when their renewal date has passed (0 days remaining).
                        </p>
                        <p>
                            <strong className={'text-gray-300'}>Free Server Renewal:</strong> Users can only renew
                            their free servers when there are {suspensionThreshold} days or fewer remaining until
                            expiration. This prevents abuse of the free server system.
                        </p>
                        <p>
                            <strong className={'text-gray-300'}>Paid Server Renewal:</strong> Users with paid servers
                            can renew at any time, regardless of how many days remain.
                        </p>
                    </div>
                </AdminBox>
            </div>
        </div>
    );
};
