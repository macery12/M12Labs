import type { FormikHelpers } from 'formik';
import { Formik } from 'formik';
import { useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import tw from 'twin.macro';
import { object, string } from 'yup';
import http, { httpErrorToHuman } from '@/api/http';
import LoginFormContainer from '@/components/auth/LoginFormContainer';
import { Button } from '@/elements/button';
import Field from '@/elements/Field';
import useFlash from '@/plugins/useFlash';

interface Values {
    account_identifier: string;
    discord_username: string;
    contact_email: string;
    reason: string;
}

function RequestAdminResetContainer() {
    const navigate = useNavigate();
    const { clearFlashes, addFlash } = useFlash();

    useEffect(() => {
        clearFlashes();
    }, []);

    const handleSubmission = (
        { account_identifier, discord_username, contact_email, reason }: Values,
        { setSubmitting, resetForm }: FormikHelpers<Values>,
    ) => {
        clearFlashes();

        // Validate at least one contact method
        if (!discord_username && !contact_email) {
            addFlash({
                type: 'error',
                title: 'Error',
                message: 'You must provide at least one contact method (Discord username or email).',
            });
            setSubmitting(false);
            return;
        }

        const data: any = { account_identifier, reason };
        if (discord_username) data.discord_username = discord_username;
        if (contact_email) data.contact_email = contact_email;

        http.post('/auth/password-reset-request', data)
            .then(() => {
                resetForm();
                addFlash({
                    type: 'success',
                    title: 'Success',
                    message:
                        'Your password reset request has been submitted. An administrator will review it shortly.',
                });
                setTimeout(() => navigate('/auth/login'), 2000);
            })
            .catch(error => {
                console.error(error);
                addFlash({ type: 'error', title: 'Error', message: httpErrorToHuman(error) });
            })
            .finally(() => setSubmitting(false));
    };

    return (
        <Formik
            onSubmit={handleSubmission}
            initialValues={{ account_identifier: '', discord_username: '', contact_email: '', reason: '' }}
            validationSchema={object().shape({
                account_identifier: string().required('You must provide your email or username.'),
                discord_username: string(),
                contact_email: string().email('Must be a valid email address.'),
                reason: string()
                    .min(10, 'Please provide a detailed reason (at least 10 characters).')
                    .required('You must provide a reason for your password reset request.'),
            })}
        >
            {({ isSubmitting }) => (
                <LoginFormContainer title={'Request Admin Password Reset'} css={tw`w-full flex`}>
                    <div css={tw`bg-blue-500/10 border border-blue-500/30 rounded-md p-4 mb-6`}>
                        <p css={tw`text-sm text-blue-200`}>
                            If you cannot access your email or recovery code, you can request assistance from an
                            administrator. Please provide your account information and at least one way to contact you.
                        </p>
                    </div>

                    <Field
                        label={'Email or Username'}
                        description={'The email address or username associated with your account.'}
                        name={'account_identifier'}
                        type={'text'}
                    />

                    <div className={'mt-6'}>
                        <Field
                            label={'Discord Username (Optional)'}
                            description={'Your Discord username so administrators can contact you.'}
                            name={'discord_username'}
                            type={'text'}
                        />
                    </div>

                    <div className={'mt-6'}>
                        <Field
                            label={'Contact Email (Optional)'}
                            description={'An email address where administrators can reach you.'}
                            name={'contact_email'}
                            type={'email'}
                        />
                    </div>

                    <div className={'mt-6'}>
                        <Field
                            label={'Reason for Request'}
                            description={
                                'Please explain why you need your password reset and why you cannot use the normal recovery process.'
                            }
                            name={'reason'}
                            type={'textarea'}
                        />
                    </div>

                    <div css={tw`mt-6`}>
                        <Button type={'submit'} size={Button.Sizes.Large} disabled={isSubmitting} css={tw`w-full`}>
                            Submit Request
                        </Button>
                    </div>

                    <div css={tw`mt-6 text-center`}>
                        <Link
                            to={'/auth/password'}
                            css={tw`text-xs text-neutral-400 tracking-wide no-underline uppercase hover:text-neutral-200 transition-colors mr-4`}
                        >
                            Back to Password Reset
                        </Link>
                        <Link
                            to={'/auth/login'}
                            css={tw`text-xs text-neutral-400 tracking-wide no-underline uppercase hover:text-neutral-200 transition-colors`}
                        >
                            Return to Login
                        </Link>
                    </div>
                </LoginFormContainer>
            )}
        </Formik>
    );
}

export default RequestAdminResetContainer;
