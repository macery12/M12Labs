import React, { FormEvent, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { completeDonation } from '@/api/routes/account/donations';
import { useNavigate } from 'react-router-dom';

interface Props {
    intentId: string;
}

export default ({ intentId }: Props) => {
    const stripe = useStripe();
    const elements = useElements();
    const navigate = useNavigate();
    const [loading, setLoading] = useState(false);
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();

    const handleSubmit = async (event: FormEvent) => {
        clearFlashes();
        setLoading(true);
        event.preventDefault();

        if (!stripe || !elements) {
            setLoading(false);
            return;
        }

        try {
            const { error, paymentIntent } = await stripe.confirmPayment({
                elements,
                redirect: 'if_required',
            });

            if (error) {
                clearAndAddHttpError({ key: 'account:donation', error: error.message });
                setLoading(false);
                return;
            }

            if (paymentIntent && paymentIntent.status === 'succeeded') {
                await completeDonation(intentId);
                addFlash({
                    key: 'account:donation',
                    type: 'success',
                    message: 'Thank you for your donation! Your support is greatly appreciated.',
                });
                navigate('/account/donations/history');
            }
        } catch (error) {
            clearAndAddHttpError({ key: 'account:donation', error });
        } finally {
            setLoading(false);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <PaymentElement />
            <SpinnerOverlay visible={loading} />
            <FlashMessageRender byKey={'account:donation'} className={'mb-4'} />
            <Button className={'mt-4 w-full'} size={Button.Sizes.Large} disabled={!stripe || loading}>
                Complete Donation
            </Button>
        </form>
    );
};
