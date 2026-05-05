import { FormEvent, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { completeDonation } from '@/api/routes/account/donations';
import { useNavigate } from 'react-router-dom';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faHeart, faLock } from '@fortawesome/free-solid-svg-icons';

interface Props {
    intentId: string;
    amount: number;
}

export default ({ intentId, amount }: Props) => {
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
            <div css={tw`mb-6`}>
                <PaymentElement />
            </div>

            <div css={tw`mb-4 p-3 rounded bg-gray-800/50 border border-gray-700`}>
                <div css={tw`flex items-center text-sm text-gray-400`}>
                    <FontAwesomeIcon icon={faLock} css={tw`mr-2`} />
                    <span>Secure payment powered by Stripe</span>
                </div>
            </div>

            <SpinnerOverlay visible={loading} />
            <FlashMessageRender byKey={'account:donation'} css={tw`mb-4`} />

            <Button type={'submit'} css={tw`w-full`} size={Button.Sizes.Large} disabled={!stripe || loading}>
                <FontAwesomeIcon icon={faHeart} css={tw`mr-2`} />
                {loading ? 'Processing...' : `Donate $${amount.toFixed(2)}`}
            </Button>
        </form>
    );
};
