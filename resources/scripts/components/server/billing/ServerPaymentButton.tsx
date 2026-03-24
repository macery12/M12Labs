import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import { FormEvent, useState } from 'react';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { createCheckoutSession } from '@/api/routes/account/billing/orders/process';
import { Product } from '@/api/definitions/account/billing';
import { ServerContext } from '@/state/server';
import { useStoreState } from '@/state/hooks';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCreditCard } from '@fortawesome/free-solid-svg-icons';

export default ({ product }: { product: Product }) => {
    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const settings = useStoreState(s => s.everest.data!.billing);
    const server = ServerContext.useStoreState(state => state.server.data!);
    const [loading, setLoading] = useState<boolean>(false);

    const handleSubmit = async (event: FormEvent) => {
        clearFlashes('server:billing:payment');
        setLoading(true);
        event.preventDefault();

        createCheckoutSession(product.id, undefined, Number(server.internalId))
            .then(url => window.location.assign(url))
            .catch(error => clearAndAddHttpError({ key: 'server:billing:payment', error }))
            .finally(() => setLoading(false));
    };

    const days = settings.renewal.days;
    const updatedRenewalDate = new Date(server.renewalDate!.getTime() + days * 24 * 60 * 60 * 1000);

    return (
        <form onSubmit={handleSubmit}>
            <SpinnerOverlay visible={loading} />
            <FlashMessageRender byKey={'server:billing:payment'} className={'mb-4'} />
            <p className={'mb-4'}>
                Renewing your server now will add another {days} days to your server, making your renewal date{' '}
                {new Date(updatedRenewalDate).toLocaleDateString()} (+{days} days).
            </p>
            <div className={'text-right'}>
                <Button className={'mt-4'} size={Button.Sizes.Large}>
                    Pay Now <FontAwesomeIcon icon={faCreditCard} className={'ml-2'} />
                </Button>
            </div>
        </form>
    );
};
