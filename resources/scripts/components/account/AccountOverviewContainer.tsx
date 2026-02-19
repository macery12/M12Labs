import ContentBox from '@/elements/ContentBox';
import UpdatePasswordForm from '@account/forms/UpdatePasswordForm';
import UpdateEmailAddressForm from '@account/forms/UpdateEmailAddressForm';
import ConfigureTwoFactorForm from '@account/forms/ConfigureTwoFactorForm';
import PageContentBlock from '@/elements/PageContentBlock';
import tw from 'twin.macro';
import { breakpoint } from '@/assets/theme';
import styled from 'styled-components';
import MessageBox from '@/elements/MessageBox';
import { useLocation } from 'react-router-dom';
import ScopedAlert from '@/components/account/ScopedAlert';
import EmailVerificationNotice from '@account/EmailVerificationNotice';
import Pill from '@/elements/Pill';
import { useStoreState } from '@/state/hooks';

const Container = styled.div`
    ${tw`flex flex-wrap`};

    & > div {
        ${tw`w-full`};

        ${breakpoint('sm')`
      width: calc(50% - 1rem);
    `}

        ${breakpoint('md')`
      ${tw`w-auto flex-1`};
    `}
    }
`;

export default () => {
    const { state } = useLocation();
    const user = useStoreState(s => s.user.data!);
    const emailEnabled = useStoreState(s => !!s.everest.data?.email?.resend?.enabled);

    return (
        <PageContentBlock title="Account Overview" header description={'Update your email, password, or setup 2-FA.'}>
            <ScopedAlert scope="account" position="top-center" />
            {emailEnabled && <EmailVerificationNotice />}
            {state?.twoFactorRedirect && (
                <MessageBox title="2-Factor Required" type="error">
                    Your account must have two-factor authentication enabled in order to continue.
                </MessageBox>
            )}

            <Container css={[tw`lg:grid lg:grid-cols-3 mb-10`, state?.twoFactorRedirect ? tw`mt-4` : tw`mt-10`]}>
                <ContentBox title="Update Password" showFlashes="account:password">
                    <UpdatePasswordForm />
                </ContentBox>

                <ContentBox
                    css={tw`mt-8 sm:mt-0 sm:ml-8`}
                    title={`Update Email Address ${
                        emailEnabled ? '' : '(email sending disabled)'
                    }`}
                    showFlashes="account:email"
                >
                    <div className="mb-4 flex items-center justify-between">
                        <span className="text-sm text-gray-300">Status</span>
                        <Pill type={user.emailVerified ? 'success' : 'warn'}>
                            {user.emailVerified ? 'Verified' : 'Not Verified'}
                        </Pill>
                    </div>
                    <UpdateEmailAddressForm />
                </ContentBox>

                <ContentBox css={tw`md:ml-8 mt-8 md:mt-0`} title="Two-Step Verification">
                    <ConfigureTwoFactorForm />
                </ContentBox>
            </Container>
        </PageContentBlock>
    );
};
