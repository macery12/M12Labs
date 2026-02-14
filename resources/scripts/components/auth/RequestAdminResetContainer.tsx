import type { FormikHelpers } from 'formik';
import { Formik } from 'formik';
import { useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import tw from 'twin.macro';
import { object, string } from 'yup';
import { createPasswordResetRequest } from '@/api/routes/account/password-reset-requests';
import { httpErrorToHuman } from '@/api/http';
import LoginFormContainer from '@/components/auth/LoginFormContainer';
import { Button } from '@/elements/button';
import Field from '@/elements/Field';
import useFlash from '@/plugins/useFlash';

interface Values {
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
        { discord_username, contact_email, reason }: Values,
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

        const data: any = { reason };
        if (discord_username) data.discord_username = discord_username;
        if (contact_email) data.contact_email = contact_email;

        createPasswordResetRequest(data)
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
            initialValues={{ discord_username: '', contact_email: '', reason: '' }}
            validationSchema={object().shape({
                discord_username: string(),
                contact_email: string().email('Must be a valid email address.'),
                reason: string()
                    .min(10, 'Please provide a detailed reason (at least 10 characters).')
                    .required('You must provide a reason for your password reset request.'),
            })}
        >
            {({ isSubmitting }) => (
                <LoginFormContainer title={'Request Admin Password Reset'} css={tw`w-full flex`}>
                    <p css={tw`text-sm text-neutral-300 mb-6`}>
                        If you cannot access your email or recovery code, you can request assistance from an
                        administrator. Please provide at least one way to contact you and explain why you need help.
                    </p>

                    <Field
                        label={'Discord Username (Optional)'}
                        description={'Your Discord username so administrators can contact you.'}
                        name={'discord_username'}
                        type={'text'}
                    />

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
                            description={'Please explain why you need your password reset and why you cannot use the normal recovery process.'}
                            name={'reason'}
                            type={'textarea'}
                        />
                    </div>

                    <div css={tw`mt-6`}>
                        <Button type={'submit'} className={'w-full'} size={Button.Sizes.Large} disabled={isSubmitting}>
                            Submit Request
                        </Button>
                    </div>

                    <div css={tw`mt-6 text-center`}>
                        <Link
                            to={'/auth/password'}
                            css={tw`text-xs text-neutral-300 tracking-wide no-underline uppercase font-medium hover:text-neutral-600 mr-4`}
                        >
                            Back to Password Reset
                        </Link>
                        <Link
                            to={'/auth/login'}
                            css={tw`text-xs text-neutral-300 tracking-wide no-underline uppercase font-medium hover:text-neutral-600`}
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
