import Spinner from '@/elements/Spinner';
import { Elements } from '@stripe/react-stripe-js';
import { loadStripe, Stripe } from '@stripe/stripe-js';
import { useEffect, useState } from 'react';
import PaymentForm from './PaymentForm';
import MolliePaymentForm from './MolliePaymentForm';
import { ServerContext } from '@/state/server';
import { StripeIntent } from '@definitions/account/billing';
import { getStripeIntent, getStripeKey } from '@/api/routes/account/billing/orders/stripe';
import { useStoreState } from '@/state/hooks';

export default ({ id, couponId }: { id?: number; couponId?: number }) => {
    const [stripe, setStripe] = useState<Stripe | null>(null);
    const [intent, setIntent] = useState<StripeIntent | null>(null);

    const serverId = ServerContext.useStoreState(state => state.server.data!.internalId);
    const serverUuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const billing = useStoreState(state => state.everest.data!.billing);

    useEffect(() => {
        const fetchData = async () => {
            if (id && billing.processor === 'stripe') {
                try {
                    const intentData = await getStripeIntent(id, couponId);
                    setIntent({ id: intentData.id, secret: intentData.secret });

                    const stripePublicKey = await getStripeKey(id);
                    const stripeInstance = await loadStripe(stripePublicKey.key);
                    setStripe(stripeInstance);
                } catch (error) {
                    console.error('Error fetching data:', error);
                }
            }
        };

        fetchData();
    }, [id, couponId, billing.processor]);

    if (!id) return <Spinner size={'large'} centered />;

    // For Mollie, we don't need to wait for intent or stripe
    if (billing.processor === 'mollie') {
        return <MolliePaymentForm id={id} serverId={Number(serverId)} serverUuid={serverUuid} couponId={couponId} />;
    }

    // For Stripe, we need both intent and stripe instance
    if (!intent || !stripe) return <Spinner size={'large'} centered />;

    const options = {
        clientSecret: intent.secret,
        appearance: {
            theme: 'night',
            variables: {
                colorText: '#ffffff',
            },
        },
    };

    return (
        <>
            {/* @ts-expect-error this is fine, stripe library is just weird */}
            {/* Key prop forces re-mount when intent changes (e.g., coupon applied/removed) */}
            <Elements stripe={stripe} options={options} key={intent?.id}>
                <PaymentForm id={id} serverId={Number(serverId)} serverUuid={serverUuid} intent={intent.id} renewal />
            </Elements>
        </>
    );
};
