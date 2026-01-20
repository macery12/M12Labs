import { useState } from 'react';
import AdminContentBlock from '@/elements/AdminContentBlock';
import DonationsTable from './DonationsTable';
import TitledGreyBox from '@/elements/TitledGreyBox';
import { faHeart, faInfoCircle, faEye, faEyeSlash } from '@fortawesome/free-solid-svg-icons';
import { useStoreState, useStoreActions } from '@/state/hooks';
import { updateSettings } from '@/api/routes/admin/billing';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import SpinnerOverlay from '@/elements/SpinnerOverlay';

export default () => {
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const donationsEnabled = useStoreState(state => state.everest.data!.billing.donations_enabled);
    const updateEverest = useStoreActions(actions => actions.everest.updateEverest);
    const [loading, setLoading] = useState(false);

    const toggleDonations = async () => {
        clearFlashes('admin:billing:donations');
        setLoading(true);

        try {
            const newValue = !donationsEnabled;
            await updateSettings('donations_enabled', newValue);
            updateEverest({
                billing: {
                    ...useStoreState.getState().everest.data!.billing,
                    donations_enabled: newValue,
                },
            });
        } catch (error) {
            clearAndAddHttpError({ key: 'admin:billing:donations', error });
        } finally {
            setLoading(false);
        }
    };

    return (
        <AdminContentBlock title={'Donations'}>
            <SpinnerOverlay visible={loading} />
            <div className={'flex w-full flex-row items-center justify-between p-8'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>
                        <FontAwesomeIcon icon={faHeart} className={'mr-2'} />
                        Donations
                    </h2>
                    <p
                        className={
                            'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                        }
                    >
                        View all donations made by users on this panel.
                    </p>
                </div>
                <div className={'flex-shrink-0'}>
                    <Button
                        onClick={toggleDonations}
                        variant={donationsEnabled ? 'filled' : 'outlined'}
                        color={donationsEnabled ? 'green' : 'red'}
                    >
                        <FontAwesomeIcon icon={donationsEnabled ? faEye : faEyeSlash} className={'mr-2'} />
                        {donationsEnabled ? 'Donations Visible' : 'Donations Hidden'}
                    </Button>
                </div>
            </div>
            <TitledGreyBox icon={faInfoCircle} title={'About Donations'} className={'mb-8'}>
                <p className={'mb-2'}>
                    Donations are voluntary contributions from users that do not grant any server resources or benefits.
                    All donations are processed through Stripe and recorded here for tracking purposes.
                </p>
                <p className={'text-sm text-yellow-400'}>
                    <strong>Note:</strong> When donations are hidden, the &quot;Donate&quot; tab will not appear in user
                    navigation. The donations functionality remains intact and can be re-enabled at any time.
                </p>
            </TitledGreyBox>
            <DonationsTable />
        </AdminContentBlock>
    );
};
