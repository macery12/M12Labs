import { useLocation, useNavigate } from 'react-router-dom';
import { useStoreState } from '@/state/hooks';
import PageContentBlock from '@/elements/PageContentBlock';
import { useEffect } from 'react';
import useFlash from '@/plugins/useFlash';
import FlashMessageRender from '@/elements/FlashMessageRender';
import Spinner from '@/elements/Spinner';
import { processPaidOrder } from '@/api/routes/account/billing/orders/process';
import { checkMolliePaymentStatus, getPaymentIdFromToken } from '@/api/routes/account/billing/orders/mollie';

export default () => {
    const location = useLocation();
    const navigate = useNavigate();
    const params = new URLSearchParams(location.search);
    const { colors } = useStoreState(s => s.theme.data!);
    const billing = useStoreState(s => s.everest.data!.billing);
    const { addFlash, clearFlashes } = useFlash();

    const stripeIntent = params.get('payment_intent');
    const mollieToken = params.get('token');

    useEffect(() => {
        clearFlashes();

        const renewal = Boolean(params.get('renewal'));
        const serverUuid = params.get('server_uuid');

        // Handle Stripe payment
        if (stripeIntent) {
            processPaidOrder(stripeIntent, renewal)
                .then(() => {
                    // Redirect to server billing page for renewals with full page reload, otherwise to success page
                    if (renewal && serverUuid) {
                        window.location.href = `/server/${serverUuid}/billing`;
                    } else {
                        navigate('/account/billing/success');
                    }
                })
                .catch(() => {
                    navigate('/account/billing/cancel');
                });
            return;
        }

        // Handle Mollie payment
        if (billing.processor === 'mollie' && mollieToken) {
            // Get payment ID from token
            getPaymentIdFromToken(mollieToken)
                .then(({ payment_id }) => {
                    // Poll for order status since Mollie processes via webhook
                    const checkStatus = async () => {
                        try {
                            const status = await checkMolliePaymentStatus(payment_id);
                            
                            if (status.processed) {
                                // Order has been processed successfully
                                if (renewal && serverUuid) {
                                    window.location.href = `/server/${serverUuid}/billing`;
                                } else {
                                    navigate('/account/billing/success');
                                }
                            } else if (status.failed) {
                                navigate('/account/billing/cancel');
                            } else {
                                // Still processing, check again after a delay
                                setTimeout(checkStatus, 2000);
                            }
                        } catch (error) {
                            console.error('Error checking Mollie payment status:', error);
                            addFlash({
                                key: 'billing:process',
                                type: 'error',
                                message: 'Unable to verify payment status. Please contact an administrator.',
                            });
                        }
                    };

                    checkStatus();
                })
                .catch((error) => {
                    console.error('Error retrieving payment ID from token:', error);
                    addFlash({
                        key: 'billing:process',
                        type: 'error',
                        message: 'Invalid payment token. Please contact an administrator.',
                    });
                });
            return;
        }

        // No payment method detected
        addFlash({
            key: 'billing:process',
            type: 'error',
            message: 'Your order could not be fulfilled. Please contact an administrator.',
        });
    }, []);

    return (
        <PageContentBlock>
            <div className={'flex justify-center'}>
                <div
                    className={'relative w-full rounded-lg p-12 text-center shadow-lg sm:w-3/4 md:w-1/2'}
                    style={{ backgroundColor: colors.secondary }}
                >
                    <FlashMessageRender byKey={'billing:process'} className={'mb-6'} />
                    <h2 className={'text-4xl font-bold text-white'}>
                        Processing Order <Spinner centered />
                    </h2>
                    <p className={'mt-2 text-sm text-neutral-200'}>
                        Our systems are currently working on deploying your server to our systems. Sit tight while your
                        new server is deployed!
                    </p>
                    <p className={'mt-8 text-2xs text-neutral-400'}>Session {stripeIntent || mollieToken || 'Unknown'}</p>
                </div>
            </div>
        </PageContentBlock>
    );
};
