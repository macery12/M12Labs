import { FormEvent, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import FlashMessageRender from '@/elements/FlashMessageRender';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { Product } from '@definitions/account/billing';
import { createMolliePayment, updateMolliePayment } from '@/api/routes/account/billing/orders/mollie';

interface Props {
    selectedNode?: number;
    product: Product;
    vars: Map<string, string>;
    couponId?: number;
    selectedEggId?: number;
    serverName: string;
    domainPayload?: Array<{
        domain_id: number;
        subdomain: string;
        port: number;
        protocol: 'tcp' | 'udp' | 'both';
        ssl_enabled?: boolean;
    }>;
}

export default (data: Props) => {
    const [loading, setLoading] = useState(false);
    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const handleSubmit = async (event: FormEvent) => {
        clearFlashes();
        setLoading(true);
        event.preventDefault();

        if (!data.product || !data.selectedNode) {
            setLoading(false);
            return;
        }

        const returnUrl = window.location.origin + '/account/billing/processing?processor=mollie';

        try {
            // Create Mollie payment with return URL
            const payment = await createMolliePayment(Number(data.product.id), data.couponId, returnUrl);

            // Update payment with order details
            const variables = Array.from(data.vars, ([key, value]) => ({ key, value }));
            await updateMolliePayment({
                id: Number(data.product.id),
                paymentId: payment.id,
                nodeId: data.selectedNode!,
                vars: variables,
                couponId: data.couponId,
                eggId: data.selectedEggId,
                name: data.serverName,
                domainPayload: data.domainPayload,
            });

            // Redirect to Mollie checkout
            // After payment, Mollie will redirect back to return_url with token parameter
            window.location.href = payment.checkout_url;
        } catch (error) {
            clearAndAddHttpError({ key: 'account:billing:order', error });
            setLoading(false);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <SpinnerOverlay visible={loading} />
            <FlashMessageRender byKey={'store:order'} className={'mb-4'} />
            <Button
                disabled={!data.selectedNode || !data.serverName.trim()}
                className={'mt-4 w-full'}
                size={Button.Sizes.Large}
            >
                Pay with Mollie
            </Button>
        </form>
    );
};
