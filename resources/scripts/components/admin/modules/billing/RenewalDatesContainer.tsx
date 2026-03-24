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
    const [loading, setLoading] = useState(false);
    const { clearFlashes, addFlash } = useFlash();

    const settings = useStoreState(s => s.everest.data!.billing);
    const updateEverest = useStoreActions(s => s.everest.updateEverest);

    const [days, setDays] = useState<number>(settings.renewal.days);
    const [threshold, setThreshold] = useState<number>(settings.renewal.threshold);

    const handleSaveAll = async () => {
        clearFlashes('admin:billing');
        setLoading(true);

        try {
            await updateSettings('renewal:days', days);
            await updateSettings('renewal:threshold', threshold);

            updateEverest({
                billing: {
                    ...settings,
                    renewal: {
                        ...settings.renewal,
                        days: days,
                        threshold: threshold,
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
                message: 'Failed to update renewal settings.',
            });
        } finally {
            setLoading(false);
        }
    };

    return (
        <div>
            <FlashMessageRender byKey={'admin:billing'} className={'mb-4'} />

            <div className={'grid lg:grid-cols-2 gap-4'}>
                <AdminBox title={'Renewal Days Addition'} icon={faCalendar}>
                    <p className={'text-gray-400 mb-4'}>
                        Number of days that should be added to a billable server when a renewal is processed, by default
                        30 days.
                    </p>
                    <div>
                        <Label>Days</Label>
                        <Input
                            type={'number'}
                            min={1}
                            max={365}
                            value={days}
                            onChange={e => setDays(parseInt(e.target.value))}
                            disabled={loading}
                        />
                        <p className={'text-xs text-gray-500 mt-2'}>
                            When a billable server is purchased or renewed, it will be active for this many days.
                        </p>
                    </div>
                </AdminBox>

                <AdminBox title={'Deletion Threshold'} icon={faClock}>
                    <p className={'text-gray-400 mb-4'}>
                        Number of days after expiration before a billable server is automatically deleted.
                    </p>
                    <div>
                        <Label>Days</Label>
                        <Input
                            type={'number'}
                            min={0}
                            max={90}
                            value={threshold}
                            onChange={e => setThreshold(parseInt(e.target.value))}
                            disabled={loading}
                        />
                        <p className={'text-xs text-gray-500 mt-2'}>
                            Billable servers will be deleted after this many days of missing the renewal.
                        </p>
                    </div>
                </AdminBox>
            </div>

            <div className={'flex justify-end mt-6'}>
                <Button onClick={handleSaveAll} disabled={loading}>
                    {loading ? 'Saving...' : 'Save All Settings'}
                </Button>
            </div>
        </div>
    );
};
