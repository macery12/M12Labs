import { FormEvent, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import FlashMessageRender from '@/elements/FlashMessageRender';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { Product } from '@definitions/account/billing';
import { createPayPalOrder, updatePayPalOrder } from '@/api/routes/account/billing/orders/paypal';

interface Props {
    selectedNode?: number;
    product: Product;
    vars: Map<string, string>;
    couponId?: number;
    selectedEggId?: number;
    serverName: string;
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

        const returnUrl = window.location.origin + '/account/billing/processing?processor=paypal';

        try {
            // Create PayPal order with return URL
            const order = await createPayPalOrder(Number(data.product.id), data.couponId, returnUrl);

            // Update order with order details
            const variables = Array.from(data.vars, ([key, value]) => ({ key, value }));
            await updatePayPalOrder({
                id: Number(data.product.id),
                orderId: order.id,
                nodeId: data.selectedNode!,
                vars: variables,
                couponId: data.couponId,
                eggId: data.selectedEggId,
                name: data.serverName,
            });

            // Redirect to PayPal approval page
            // After approval, PayPal will redirect back to return_url with token parameter
            window.location.href = order.approval_url;
        } catch (error) {
            clearAndAddHttpError({ key: 'store:order', error });
            setLoading(false);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <SpinnerOverlay visible={loading} />
            <FlashMessageRender byKey={'store:order'} className={'mb-4'} />
            <Button
                type={'submit'}
                disabled={!data.selectedNode || !data.serverName.trim()}
                className={'mt-4 w-full'}
                size={Button.Sizes.Large}
            >
                Pay with PayPal
            </Button>
        </form>
    );
};
