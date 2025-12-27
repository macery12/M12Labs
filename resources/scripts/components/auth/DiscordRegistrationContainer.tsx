import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Formik, FormikHelpers } from 'formik';
import { object, string } from 'yup';
import tw from 'twin.macro';

import LoginFormContainer from '@/components/auth/LoginFormContainer';
import Field from '@/elements/Field';
import { Button } from '@/elements/button';
import useFlash from '@/plugins/useFlash';
import { getDiscordRegistrationData, completeDiscordRegistration } from '@/api/routes/auth/discord';
import { faIdBadge, faKey, faUnlockKeyhole } from '@fortawesome/free-solid-svg-icons';
import { faDiscord } from '@fortawesome/free-brands-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import Checkbox from '@/elements/inputs/Checkbox';

interface Values {
    username: string;
    password: string;
    confirm_password: string;
    use_discord_only: boolean;
}

function DiscordRegistrationContainer() {
    const navigate = useNavigate();
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const [discordData, setDiscordData] = useState<{
        discord_username: string;
        discord_email: string;
        discord_id: string;
    } | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        clearFlashes();

        getDiscordRegistrationData()
            .then(data => {
                setDiscordData(data);
                setLoading(false);
            })
            .catch(error => {
                console.error(error);
                clearAndAddHttpError({ error });
                // Redirect back to login if no Discord data found
                setTimeout(() => navigate('/auth/login'), 2000);
            });
    }, []);

    const onSubmit = (values: Values, { setSubmitting }: FormikHelpers<Values>) => {
        clearFlashes();

        completeDiscordRegistration({
            username: values.username,
            password: values.password,
            confirm_password: values.confirm_password,
            use_discord_only: values.use_discord_only,
        })
            .then(() => {
                // @ts-expect-error this is valid
                window.location = '/';
            })
            .catch(error => {
                console.error(error);
                setSubmitting(false);
                clearAndAddHttpError({ error });
            });
    };

    if (loading) {
        return (
            <LoginFormContainer title={'Complete Your Registration'}>
                <div css={tw`text-center py-8`}>
                    <p css={tw`text-gray-400`}>Loading Discord information...</p>
                </div>
            </LoginFormContainer>
        );
    }

    if (!discordData) {
        return (
            <LoginFormContainer title={'Error'}>
                <div css={tw`text-center py-8`}>
                    <p css={tw`text-red-400`}>Discord registration data not found. Redirecting...</p>
                </div>
            </LoginFormContainer>
        );
    }

    return (
        <Formik
            onSubmit={onSubmit}
            initialValues={{
                username: discordData.discord_username || '',
                password: '',
                confirm_password: '',
                use_discord_only: false,
            }}
            validationSchema={object().shape({
                username: string().required('A username must be provided.'),
                password: string().when('use_discord_only', {
                    is: false,
                    then: schema => schema.required('Please enter your account password.'),
                    otherwise: schema => schema.notRequired(),
                }),
                confirm_password: string().when('use_discord_only', {
                    is: false,
                    then: schema => schema.required('Please enter the password confirmation.'),
                    otherwise: schema => schema.notRequired(),
                }),
            })}
        >
            {({ isSubmitting, values, setFieldValue }) => (
                <LoginFormContainer title={'Complete Your Discord Registration'}>
                    <div css={tw`mb-6 p-4 bg-blue-500/10 border border-blue-500/30 rounded`}>
                        <div css={tw`flex items-center mb-2`}>
                            <FontAwesomeIcon icon={faDiscord} css={tw`text-blue-400 mr-2`} />
                            <span css={tw`text-sm font-semibold text-blue-400`}>Discord Account</span>
                        </div>
                        <p css={tw`text-xs text-gray-400`}>
                            Email: <span css={tw`text-gray-300`}>{discordData.discord_email}</span>
                        </p>
                        <p css={tw`text-xs text-gray-400`}>
                            Discord ID: <span css={tw`text-gray-300`}>{discordData.discord_id}</span>
                        </p>
                    </div>

                    <Field
                        type={'text'}
                        label={'Username'}
                        icon={faIdBadge}
                        name={'username'}
                        placeholder={'Choose a username'}
                        disabled={isSubmitting}
                        description={'This will be your display name on the panel'}
                    />

                    <div css={tw`mt-6`}>
                        <Checkbox
                            name={'use_discord_only'}
                            label={'Use Discord SSO only (no password)'}
                            description={'You will only be able to login using Discord. This is more secure but requires Discord to be available.'}
                            disabled={isSubmitting}
                        />
                    </div>

                    {!values.use_discord_only && (
                        <>
                            <div css={tw`mt-6`}>
                                <Field
                                    type={'password'}
                                    label={'Password (Optional with Discord)'}
                                    icon={faKey}
                                    name={'password'}
                                    placeholder={'••••••••••••'}
                                    disabled={isSubmitting}
                                    description={'Set a password to enable traditional login in addition to Discord SSO'}
                                />
                            </div>
                            <div css={tw`mt-6`}>
                                <Field
                                    type={'password'}
                                    label={'Confirm Password'}
                                    icon={faUnlockKeyhole}
                                    name={'confirm_password'}
                                    placeholder={'••••••••••••'}
                                    disabled={isSubmitting}
                                />
                            </div>
                        </>
                    )}

                    <div css={tw`mt-6`}>
                        <Button
                            type={'submit'}
                            loading={isSubmitting}
                            className={'w-full'}
                            size={Button.Sizes.Large}
                            disabled={isSubmitting}
                        >
                            Complete Registration
                        </Button>
                    </div>

                    <div css={tw`mt-6 text-center`}>
                        <button
                            type={'button'}
                            onClick={() => navigate('/auth/login')}
                            css={tw`text-xs text-neutral-300 tracking-wide no-underline uppercase font-medium hover:text-neutral-600`}
                        >
                            Cancel and Return to Login
                        </button>
                    </div>
                </LoginFormContainer>
            )}
        </Formik>
    );
}

export default DiscordRegistrationContainer;
