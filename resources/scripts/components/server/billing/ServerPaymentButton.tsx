import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import { FormEvent, useState } from 'react';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { createCheckoutSession } from '@/api/routes/account/billing/orders/process';
import { Product } from '@/api/definitions/account/billing';
import { ServerContext } from '@/state/server';

export default ({ product }: { product: Product }) => {
    const { clearFlashes, clearAndAddHttpError } = useFlash();

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

    return (
        <form onSubmit={handleSubmit}>
            <SpinnerOverlay visible={loading} />
            <FlashMessageRender byKey={'server:billing:payment'} className={'mb-4'} />
            <div className={'text-right'}>
                <Button className={'mt-4'} size={Button.Sizes.Large}>
                    Pay Now
                </Button>
            </div>
        </form>
    );
};
