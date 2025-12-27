import { FormEvent, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import AlertRenderer from '@/components/AlertRenderer';
import { PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { Product, StripeIntent } from '@definitions/account/billing';
import { updateStripeIntent } from '@/api/routes/account/billing/orders/stripe';

interface Props {
    selectedNode?: number;
    product: Product;
    vars: Map<string, string>;
    intent: StripeIntent;
    couponId?: number;
    selectedEggId?: number;
    serverName: string;
}

export default (data: Props) => {
    const stripe = useStripe();
    const elements = useElements();
    const [loading, setLoading] = useState(false);
    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const handleSubmit = async (event: FormEvent) => {
        clearFlashes();
        setLoading(true);
        event.preventDefault();

        if (!stripe || !elements || !data.product || !data.selectedNode) return;

        const variables = Array.from(data.vars, ([key, value]) => ({ key, value }));

        await updateStripeIntent({
            id: Number(data.product.id),
            intent: data.intent.id,
            node_id: data.selectedNode!,
            vars: variables,
            coupon_id: data.couponId,
            egg_id: data.selectedEggId,
            name: data.serverName,
        })
            .then(() => {
                stripe.confirmPayment({
                    elements,
                    confirmParams: {
                        return_url: window.location.origin + '/account/billing/processing',
                    },
                });
            })
            .catch(error => clearAndAddHttpError({ key: 'account:billing:order', error }));
    };

    return (
        <form onSubmit={handleSubmit}>
            <PaymentElement />
            <SpinnerOverlay visible={loading} />
            <AlertRenderer filterByKey={'store:order'} className={'mb-4'} position="top-center" />
            <Button
                disabled={!data.selectedNode || !data.serverName.trim()}
                className={'mt-4 w-full'}
                size={Button.Sizes.Large}
            >
                Pay Now
            </Button>
        </form>
    );
};
