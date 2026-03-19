import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import { FormEvent, useState } from 'react';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { createCheckoutSession } from '@/api/routes/account/billing/orders/process';
import { Product } from '@/api/definitions/account/billing';
import { ServerContext } from '@/state/server';
import { useStoreState } from '@/state/hooks';

export default ({ product }: { product: Product }) => {
    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const settings = useStoreState(s => s.everest.data!.billing);
    const server = ServerContext.useStoreState(state => state.server.data!);
    const [loading, setLoading] = useState<boolean>(false);
    const days = settings.renewal?.days || 30;

    const handleSubmit = async (event: FormEvent) => {
        clearFlashes('server:billing:payment');
        setLoading(true);
        event.preventDefault();

        createCheckoutSession(product.id, undefined, Number(server.internalId))
            .then(url => window.location.assign(url))
            .catch(error => clearAndAddHttpError({ key: 'server:billing:payment', error }))
            .finally(() => setLoading(false));
    };

    const nextRenewalDate = new Date(server.renewalDate!);
    nextRenewalDate.setDate(nextRenewalDate.getDate() + days);

    const formattedDate = nextRenewalDate.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });

    return (
        <form onSubmit={handleSubmit}>
            <SpinnerOverlay visible={loading} />
            <FlashMessageRender byKey={'server:billing:payment'} className={'mb-4'} />
            <p className={'text-gray-400 text-sm mb-4'}>
                Renewing your server now will add another {days} days to your server, making your renewal date{' '}
                {formattedDate} (+{days} days).
            </p>
            <div className={'text-right'}>
                <Button className={'mt-4'} size={Button.Sizes.Large}>
                    Pay Now
                </Button>
            </div>
        </form>
    );
};
