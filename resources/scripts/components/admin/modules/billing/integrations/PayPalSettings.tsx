import { useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import { faKey, faInfoCircle } from '@fortawesome/free-solid-svg-icons';
import { faPaypal } from '@fortawesome/free-brands-svg-icons';
import { useStoreState } from '@/state/hooks';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import SetupPayPalKeys from '../guides/SetupPayPalKeys';

export default () => {
    const settings = useStoreState(s => s.everest.data!.billing);
    const [paypalKeysOpen, setPaypalKeysOpen] = useState(false);

    const hasCredentials = !!(settings.paypal_standalone?.client_id && settings.paypal_standalone?.client_secret);
    const mode = settings.paypal_standalone?.mode || 'sandbox';

    return (
        <div className={'grid gap-4 lg:grid-cols-3'}>
            <SetupPayPalKeys open={paypalKeysOpen} onClose={() => setPaypalKeysOpen(false)} />

            <AdminBox title={'Configure PayPal API Credentials'} icon={faKey}>
                {!hasCredentials ? (
                    <>
                        PayPal standalone integration is enabled, but API credentials are not configured. To use
                        PayPal as a standalone payment processor, you need to configure your PayPal Client ID and
                        Client Secret. Click below to add your credentials from the PayPal Developer Dashboard.
                        <div className={'mt-3 text-right'}>
                            <Button onClick={() => setPaypalKeysOpen(true)}>Add PayPal Credentials</Button>
                        </div>
                    </>
                ) : (
                    <>
                        PayPal API credentials are configured and ready to process payments. You can update your
                        credentials by clicking the button below.
                        <div className={'mt-2'}>
                            <p className={'text-sm text-gray-400'}>
                                Current mode:{' '}
                                <span className={mode === 'live' ? 'text-green-500' : 'text-yellow-500'}>
                                    {mode === 'live' ? 'Live (Production)' : 'Sandbox (Testing)'}
                                </span>
                            </p>
                        </div>
                        <div className={'mt-3 text-right'}>
                            <Button onClick={() => setPaypalKeysOpen(true)}>Update Credentials</Button>
                        </div>
                    </>
                )}
            </AdminBox>

            <AdminBox title={'About PayPal Standalone'} icon={faPaypal}>
                PayPal standalone integration allows direct PayPal checkout without requiring Stripe. This provides
                native PayPal order management, better customer experience, and direct access to PayPal&apos;s
                features.
                <div className={'mt-3 rounded-lg bg-black/50 p-3'}>
                    <p className={'text-sm font-semibold text-gray-200'}>
                        <FontAwesomeIcon icon={faInfoCircle} className={'mr-2 text-blue-400'} />
                        Key Benefits
                    </p>
                    <ul className={'mt-2 list-inside list-disc text-sm text-gray-400'}>
                        <li>Direct PayPal integration without Stripe</li>
                        <li>Better PayPal customer experience</li>
                        <li>Support for PayPal-specific features</li>
                        <li>Lower processing complexity</li>
                    </ul>
                </div>
            </AdminBox>

            <AdminBox title={'Configuration Status'} icon={faKey}>
                <div className={'space-y-2'}>
                    <div className={'flex items-center justify-between'}>
                        <span className={'text-sm text-gray-400'}>Client ID:</span>
                        <span className={hasCredentials ? 'text-green-500' : 'text-red-500'}>
                            {hasCredentials ? 'Configured' : 'Not configured'}
                        </span>
                    </div>
                    <div className={'flex items-center justify-between'}>
                        <span className={'text-sm text-gray-400'}>Client Secret:</span>
                        <span className={hasCredentials ? 'text-green-500' : 'text-red-500'}>
                            {hasCredentials ? 'Configured' : 'Not configured'}
                        </span>
                    </div>
                    <div className={'flex items-center justify-between'}>
                        <span className={'text-sm text-gray-400'}>Mode:</span>
                        <span className={'text-gray-200'}>
                            {mode.charAt(0).toUpperCase() + mode.slice(1)}
                        </span>
                    </div>
                </div>
            </AdminBox>
        </div>
    );
};
