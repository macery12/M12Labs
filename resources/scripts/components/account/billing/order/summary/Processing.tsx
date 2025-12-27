import { useLocation, useNavigate } from 'react-router-dom';
import { useStoreState } from '@/state/hooks';
import PageContentBlock from '@/elements/PageContentBlock';
import { useEffect } from 'react';
import useFlash from '@/plugins/useFlash';
import AlertRenderer from '@/components/AlertRenderer';
import Spinner from '@/elements/Spinner';
import { processPaidOrder } from '@/api/routes/account/billing/orders/process';

export default () => {
    const location = useLocation();
    const navigate = useNavigate();
    const params = new URLSearchParams(location.search);
    const { colors } = useStoreState(s => s.theme.data!);
    const { addFlash, clearFlashes } = useFlash();

    const intent = params.get('payment_intent');

    useEffect(() => {
        clearFlashes();

        const renewal = Boolean(params.get('renewal'));
        const serverUuid = params.get('server_uuid');

        if (!intent) {
            addFlash({
                key: 'billing:process',
                type: 'error',
                message: 'Your order could not be fulfilled. Please contact an administrator.',
            });

            return;
        }

        processPaidOrder(intent, renewal)
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
    }, []);

    return (
        <PageContentBlock>
            <div className={'flex justify-center'}>
                <div
                    className={'relative w-full rounded-lg p-12 text-center shadow-lg sm:w-3/4 md:w-1/2'}
                    style={{ backgroundColor: colors.secondary }}
                >
                    <AlertRenderer filterByKey={'billing:process'} className={'mb-6'} position="top-center" />
                    <h2 className={'text-4xl font-bold text-white'}>
                        Processing Order <Spinner centered />
                    </h2>
                    <p className={'mt-2 text-sm text-neutral-200'}>
                        Our systems are currently working on deploying your server to our systems. Sit tight while your
                        new server is deployed!
                    </p>
                    <p className={'mt-8 text-2xs text-neutral-400'}>Session {intent ?? 'Unknown'}</p>
                </div>
            </div>
        </PageContentBlock>
    );
};
