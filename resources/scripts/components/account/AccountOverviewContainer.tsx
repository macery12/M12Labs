import ContentBox from '@/elements/ContentBox';
import UpdatePasswordForm from '@account/forms/UpdatePasswordForm';
import UpdateEmailAddressForm from '@account/forms/UpdateEmailAddressForm';
import ConfigureTwoFactorForm from '@account/forms/ConfigureTwoFactorForm';
import PageContentBlock from '@/elements/PageContentBlock';
import tw from 'twin.macro';
import { breakpoint } from '@/assets/theme';
import styled from 'styled-components';
import { Alert } from '@/elements/alert';
import { useLocation } from 'react-router-dom';

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

    return (
        <PageContentBlock title="Account Overview" header description={'Update your email, password, or setup 2-FA.'}>
            {state?.twoFactorRedirect && (
                <Alert type="danger">
                    <strong className="mb-2 block font-semibold">2-Factor Required</strong>
                    Your account must have two-factor authentication enabled in order to continue.
                </Alert>
            )}

            <Container css={[tw`lg:grid lg:grid-cols-3 mb-10`, state?.twoFactorRedirect ? tw`mt-4` : tw`mt-10`]}>
                <ContentBox title="Update Password" showFlashes="account:password">
                    <UpdatePasswordForm />
                </ContentBox>

                <ContentBox css={tw`mt-8 sm:mt-0 sm:ml-8`} title="Update Email Address" showFlashes="account:email">
                    <UpdateEmailAddressForm />
                </ContentBox>

                <ContentBox css={tw`md:ml-8 mt-8 md:mt-0`} title="Two-Step Verification">
                    <ConfigureTwoFactorForm />
                </ContentBox>
            </Container>
        </PageContentBlock>
    );
};
