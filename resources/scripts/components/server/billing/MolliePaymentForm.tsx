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
            // Create Mollie payment for renewal with all details in one request
            const payment = await createMolliePayment(id, couponId, returnUrl, serverId, true);

            // Redirect to Mollie checkout
            // After payment, Mollie will redirect back to return_url with token parameter
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
