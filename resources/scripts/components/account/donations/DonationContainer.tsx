import React, { useState } from 'react';
import tw from 'twin.macro';
import { Elements } from '@stripe/react-stripe-js';
import { loadStripe, StripeElementsOptions } from '@stripe/stripe-js';
import PageContentBlock from '@/elements/PageContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { Button } from '@/elements/button';
import { Field, Form, Formik, FormikHelpers } from 'formik';
import * as Yup from 'yup';
import { createDonationIntent, getStripeKey } from '@/api/routes/account/donations';
import useFlash from '@/plugins/useFlash';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import DonationPaymentForm from './DonationPaymentForm';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import ContentBox from '@/elements/ContentBox';
import TitledGreyBox from '@/elements/TitledGreyBox';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faHeart, faDollarSign, faInfoCircle, faCheckCircle } from '@fortawesome/free-solid-svg-icons';
import { useStoreState } from '@/state/hooks';

interface FormValues {
    amount: string;
    message: string;
}

const validationSchema = Yup.object().shape({
    amount: Yup.number()
        .min(1, 'Minimum donation is $1')
        .max(10000, 'Maximum donation is $10,000')
        .required('Amount is required'),
    message: Yup.string().max(500, 'Message is too long (max 500 characters)'),
});

export default () => {
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { colors } = useStoreState(state => state.theme.data!);
    const [stripePromise, setStripePromise] = useState<any>(null);
    const [clientSecret, setClientSecret] = useState<string>('');
    const [intentId, setIntentId] = useState<string>('');
    const [donationAmount, setDonationAmount] = useState<number>(0);
    const [donationMessage, setDonationMessage] = useState<string>('');
    const [loading, setLoading] = useState(false);

    const handleDonationSetup = async (values: FormValues, { setSubmitting }: FormikHelpers<FormValues>) => {
        clearFlashes();
        setLoading(true);

        try {
            const { key } = await getStripeKey();
            const stripe = await loadStripe(key);
            setStripePromise(stripe);

            const amount = parseFloat(values.amount);
            const intent = await createDonationIntent(amount, values.message || undefined);

            setClientSecret(intent.secret);
            setIntentId(intent.id);
            setDonationAmount(amount);
            setDonationMessage(values.message);
        } catch (error) {
            clearAndAddHttpError({ key: 'account:donation', error });
        } finally {
            setLoading(false);
            setSubmitting(false);
        }
    };

    const options: StripeElementsOptions = {
        clientSecret,
        appearance: {
            theme: 'night',
            variables: {
                colorPrimary: colors.primary || '#0ea5e9',
            },
        },
    };

    return (
        <PageContentBlock title={'Support Us'}>
            <FlashMessageRender byKey={'account:donation'} />

            <div css={tw`mt-8 mb-8 text-3xl font-bold lg:text-5xl`}>
                <FontAwesomeIcon icon={faHeart} css={tw`mr-3`} style={{ color: colors.primary }} />
                Make a Donation
                <p css={tw`mt-2 text-sm font-normal text-gray-400`}>
                    Your support helps us maintain and improve our services for the community.
                </p>
            </div>

            {!clientSecret ? (
                <div css={tw`grid gap-6 lg:grid-cols-3`}>
                    {/* Information Panel */}
                    <div css={tw`lg:col-span-1`}>
                        <TitledGreyBox title="About Donations" icon={faInfoCircle}>
                            <div css={tw`space-y-4 text-sm text-gray-300`}>
                                <div css={tw`flex items-start`}>
                                    <FontAwesomeIcon icon={faCheckCircle} css={tw`mt-1 mr-3 text-green-400`} />
                                    <div>
                                        <p css={tw`font-semibold text-gray-200`}>100% Voluntary</p>
                                        <p css={tw`text-gray-400`}>No obligation to donate</p>
                                    </div>
                                </div>
                                <div css={tw`flex items-start`}>
                                    <FontAwesomeIcon icon={faCheckCircle} css={tw`mt-1 mr-3 text-green-400`} />
                                    <div>
                                        <p css={tw`font-semibold text-gray-200`}>Secure Payment</p>
                                        <p css={tw`text-gray-400`}>Processed via Stripe</p>
                                    </div>
                                </div>
                                <div css={tw`flex items-start`}>
                                    <FontAwesomeIcon icon={faCheckCircle} css={tw`mt-1 mr-3 text-green-400`} />
                                    <div>
                                        <p css={tw`font-semibold text-gray-200`}>No Benefits</p>
                                        <p css={tw`text-gray-400`}>Donations don't grant server resources or credits</p>
                                    </div>
                                </div>
                            </div>
                        </TitledGreyBox>

                        <div css={tw`mt-6 p-4 rounded-lg bg-blue-900/20 border border-blue-700/50`}>
                            <div css={tw`flex items-center mb-2`}>
                                <FontAwesomeIcon icon={faHeart} css={tw`mr-2 text-red-400`} />
                                <p css={tw`text-sm font-semibold text-gray-200`}>Thank You!</p>
                            </div>
                            <p css={tw`text-xs text-gray-400`}>
                                Every contribution, no matter how small, makes a real difference in keeping our platform
                                running smoothly.
                            </p>
                        </div>
                    </div>

                    {/* Donation Form */}
                    <div css={tw`lg:col-span-2`}>
                        <ContentBox title="Donation Details">
                            <Formik
                                initialValues={{
                                    amount: '',
                                    message: '',
                                }}
                                validationSchema={validationSchema}
                                onSubmit={handleDonationSetup}
                            >
                                {({ isSubmitting, errors, touched, values }) => (
                                    <Form>
                                        <SpinnerOverlay visible={loading} />

                                        <div css={tw`mb-6`}>
                                            <Label>
                                                <FontAwesomeIcon icon={faDollarSign} css={tw`mr-2`} />
                                                Donation Amount (USD)
                                            </Label>
                                            <Field
                                                as={Input}
                                                type={'number'}
                                                name={'amount'}
                                                placeholder={'Enter amount (minimum $1)'}
                                                step={'0.01'}
                                                min={'1'}
                                                max={'10000'}
                                            />
                                            {errors.amount && touched.amount ? (
                                                <p css={tw`text-red-400 text-sm mt-1`}>{errors.amount}</p>
                                            ) : (
                                                <p css={tw`text-gray-400 text-xs mt-1`}>
                                                    Minimum: $1.00 • Maximum: $10,000.00
                                                </p>
                                            )}
                                            {values.amount && !errors.amount && (
                                                <div
                                                    css={tw`mt-3 p-3 rounded-lg border-2`}
                                                    style={{
                                                        borderColor: colors.primary,
                                                        backgroundColor: colors.secondary,
                                                    }}
                                                >
                                                    <p css={tw`text-sm text-gray-300`}>
                                                        You are donating:{' '}
                                                        <span
                                                            css={tw`text-xl font-bold`}
                                                            style={{ color: colors.primary }}
                                                        >
                                                            ${parseFloat(values.amount).toFixed(2)}
                                                        </span>
                                                    </p>
                                                </div>
                                            )}
                                        </div>

                                        <div css={tw`mb-6`}>
                                            <Label>Message (Optional)</Label>
                                            <Field
                                                as={'textarea'}
                                                name={'message'}
                                                placeholder={'Leave a message with your donation (optional)'}
                                                css={tw`shadow-md bg-neutral-800 border border-neutral-700 rounded p-3 w-full text-sm text-neutral-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500`}
                                                rows={4}
                                            />
                                            {errors.message && touched.message ? (
                                                <p css={tw`text-red-400 text-sm mt-1`}>{errors.message}</p>
                                            ) : (
                                                <p css={tw`text-gray-400 text-xs mt-1`}>
                                                    {values.message.length}/500 characters
                                                </p>
                                            )}
                                        </div>

                                        <Button
                                            type={'submit'}
                                            disabled={isSubmitting || !values.amount}
                                            size={Button.Sizes.Large}
                                            css={tw`w-full`}
                                        >
                                            <FontAwesomeIcon icon={faHeart} css={tw`mr-2`} />
                                            Continue to Payment
                                        </Button>
                                    </Form>
                                )}
                            </Formik>
                        </ContentBox>
                    </div>
                </div>
            ) : (
                <div css={tw`max-w-4xl mx-auto`}>
                    <ContentBox title="Complete Your Donation">
                        <div css={tw`mb-6 p-4 rounded-lg border-2`} style={{ borderColor: colors.primary }}>
                            <div css={tw`flex items-center justify-between mb-2`}>
                                <p css={tw`text-lg font-semibold text-gray-200`}>Donation Summary</p>
                                <p css={tw`text-2xl font-bold`} style={{ color: colors.primary }}>
                                    ${donationAmount.toFixed(2)}
                                </p>
                            </div>
                            {donationMessage && (
                                <div css={tw`mt-3 pt-3 border-t border-gray-700`}>
                                    <p css={tw`text-sm text-gray-400 mb-1`}>Your Message:</p>
                                    <p css={tw`text-sm text-gray-300 italic`}>&quot;{donationMessage}&quot;</p>
                                </div>
                            )}
                        </div>

                        {stripePromise && clientSecret && (
                            <Elements stripe={stripePromise} options={options}>
                                <DonationPaymentForm intentId={intentId} amount={donationAmount} />
                            </Elements>
                        )}
                    </ContentBox>
                </div>
            )}
        </PageContentBlock>
    );
};
