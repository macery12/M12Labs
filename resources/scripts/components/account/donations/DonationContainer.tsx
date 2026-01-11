import React, { useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components';
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

const Container = styled.div`
    ${tw`flex flex-col`};

    & > div {
        ${tw`mb-4`};
    }
`;

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
    const [stripePromise, setStripePromise] = useState<any>(null);
    const [clientSecret, setClientSecret] = useState<string>('');
    const [intentId, setIntentId] = useState<string>('');
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
                colorPrimary: '#0ea5e9',
            },
        },
    };

    return (
        <PageContentBlock title={'Make a Donation'}>
            <FlashMessageRender byKey={'account:donation'} />

            {!clientSecret ? (
                <Container>
                    <div className={'text-gray-400 mb-6'}>
                        <p className={'mb-2'}>
                            Support our service with a donation! Your contribution helps us maintain and improve the
                            platform.
                        </p>
                        <p className={'text-sm'}>
                            Please note: Donations do not provide any server benefits or resources. They are purely
                            voluntary contributions to support the service.
                        </p>
                    </div>

                    <Formik
                        initialValues={{
                            amount: '',
                            message: '',
                        }}
                        validationSchema={validationSchema}
                        onSubmit={handleDonationSetup}
                    >
                        {({ isSubmitting, errors, touched }) => (
                            <Form>
                                <SpinnerOverlay visible={loading} />

                                <div className={'mb-6'}>
                                    <Label>Donation Amount (USD)</Label>
                                    <Field
                                        as={Input}
                                        type={'number'}
                                        name={'amount'}
                                        placeholder={'Enter amount (minimum $1)'}
                                        step={'0.01'}
                                        min={'1'}
                                        max={'10000'}
                                    />
                                    {errors.amount && touched.amount && (
                                        <p className={'text-red-400 text-sm mt-1'}>{errors.amount}</p>
                                    )}
                                </div>

                                <div className={'mb-6'}>
                                    <Label>Message (Optional)</Label>
                                    <Field
                                        as={'textarea'}
                                        name={'message'}
                                        placeholder={'Leave a message with your donation'}
                                        className={
                                            'shadow-md bg-neutral-800 border border-neutral-700 rounded p-3 w-full text-sm text-neutral-200'
                                        }
                                        rows={3}
                                    />
                                    {errors.message && touched.message && (
                                        <p className={'text-red-400 text-sm mt-1'}>{errors.message}</p>
                                    )}
                                </div>

                                <Button type={'submit'} disabled={isSubmitting} size={Button.Sizes.Large}>
                                    Continue to Payment
                                </Button>
                            </Form>
                        )}
                    </Formik>
                </Container>
            ) : (
                <div className={'bg-neutral-800 rounded-lg p-6'}>
                    <h3 className={'text-xl font-bold mb-4'}>Complete Your Donation</h3>
                    {stripePromise && clientSecret && (
                        <Elements stripe={stripePromise} options={options}>
                            <DonationPaymentForm intentId={intentId} />
                        </Elements>
                    )}
                </div>
            )}
        </PageContentBlock>
    );
};
