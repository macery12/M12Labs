import { FormEvent, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { createMolliePayment, updateMolliePayment } from '@/api/routes/account/billing/orders/mollie';

export default ({
    id,
    serverId,
    serverUuid,
    couponId,
}: {
    id?: number;
    serverId: number;
    serverUuid?: string;
    couponId?: number;
}) => {
    const [loading, setLoading] = useState<boolean>(false);
    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const handleSubmit = async (event: FormEvent) => {
        clearFlashes();
        setLoading(true);
        event.preventDefault();

        if (!id) return;

        const returnUrl =
            window.location.origin + `/account/billing/processing?renewal=true&server_uuid=${serverUuid}`;

        try {
            // Create Mollie payment for renewal
            const payment = await createMolliePayment(id, couponId, returnUrl);

            // Update payment with renewal details
            await updateMolliePayment({
                id,
                paymentId: payment.id,
                serverId,
                renewal: true,
                couponId,
                name: 'Server Renewal',
            });

            // Store payment ID in localStorage so processing page can check status
            localStorage.setItem('mollie_payment_id', payment.id);

            // Redirect to Mollie checkout
            window.location.href = payment.checkout_url;
        } catch (error) {
            clearAndAddHttpError({ key: 'account:billing:renewal', error });
            setLoading(false);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <SpinnerOverlay visible={loading} />
            <div className={'text-right'}>
                <Button className={'mt-4'} size={Button.Sizes.Large}>
                    Pay with Mollie
                </Button>
            </div>
        </form>
    );
};
