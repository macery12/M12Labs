import { useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import { faKey } from '@fortawesome/free-solid-svg-icons';
import { useStoreState } from '@/state/hooks';
import SetupPayment from '../guides/SetupPayment';
import { BillingSetupDialog } from '../SettingsContainer';

export default () => {
    const settings = useStoreState(s => s.everest.data!.billing);
    const [open, setOpen] = useState<BillingSetupDialog>('none');

    return (
        <div className={'grid gap-4 lg:grid-cols-3'}>
            {open === 'payment' && <SetupPayment extOpen />}

            <AdminBox title={'Configure Mollie API Key'} icon={faKey}>
                {!settings.mollie?.api_key ? (
                    <>
                        Mollie is enabled as your payment integration, but the API key is not configured. To use Mollie,
                        you need to configure your Mollie API key. Click below to add your API key from the Mollie
                        dashboard.
                        <div className={'mt-3 text-right'}>
                            <Button onClick={() => setOpen('payment')}>Add Mollie API Key</Button>
                        </div>
                    </>
                ) : (
                    <>
                        Mollie API key is configured. You can update it by clicking the button below.
                        <div className={'mt-3 text-right'}>
                            <Button.Text onClick={() => setOpen('payment')}>Update API Key</Button.Text>
                        </div>
                    </>
                )}
            </AdminBox>

            <AdminBox title={'About Mollie'} icon={faKey}>
                Mollie is a European payment service provider that supports various payment methods including iDEAL,
                credit cards, bank transfers, and more. It&apos;s particularly popular in Europe and offers competitive
                rates.
                <div className={'mt-2'}>
                    <p className={'text-sm text-gray-400'}>
                        Current API key status:{' '}
                        <span className={settings.mollie?.api_key ? 'text-green-500' : 'text-red-500'}>
                            {settings.mollie?.api_key ? 'Configured' : 'Not configured'}
                        </span>
                    </p>
                </div>
            </AdminBox>
        </div>
    );
};
