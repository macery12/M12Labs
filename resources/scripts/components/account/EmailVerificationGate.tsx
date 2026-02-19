import PageContentBlock from '@/elements/PageContentBlock';
import { Alert } from '@/elements/alert';
import { Button } from '@/elements/button';
import { useEmailVerification } from '@/hooks/useEmailVerification';
import { useStoreState } from '@/state/hooks';
import FlashMessageRender from '@/elements/FlashMessageRender';
import React from 'react';
import {
    EMAIL_VERIFICATION_ALERT_MESSAGE,
    EMAIL_VERIFICATION_FEATURES,
    formatRestrictedFeatures,
} from '@/constants/emailVerification';

type Props = {
    children: React.ReactNode;
};

const EmailVerificationGate = ({ children }: Props) => {
    const user = useStoreState(state => state.user.data!);
    const emailEnabled = useStoreState(
        state =>
            Boolean(
                state.everest.data?.email?.enabled ??
                    state.everest.data?.email?.resend?.enabled ??
                    state.everest.data?.email?.resend,
            ),
    );
    const verification = useEmailVerification(emailEnabled) || {};
    const {
        resend = () => {},
        isCoolingDown = false,
        resendLabel = 'Resend verification email',
        refreshUser = () => {},
    } = verification as ReturnType<typeof useEmailVerification>;

    if (!emailEnabled || user.emailVerified) {
        return <>{children}</>;
    }

    return (
        <PageContentBlock title="Email verification required">
            <FlashMessageRender byKey={'account:verification'} />
            <Alert type="warning">
                <div className="space-y-3">
                    <div>
                        <strong className="block text-lg">Verify your email to continue</strong>
                        <p className="text-sm text-yellow-50">
                            {EMAIL_VERIFICATION_ALERT_MESSAGE} Restricted areas:{' '}
                            {formatRestrictedFeatures(EMAIL_VERIFICATION_FEATURES)}.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button size={Button.Sizes.Small} disabled={isCoolingDown} onClick={() => void resend()}>
                            {resendLabel}
                        </Button>
                        <Button.Text size={Button.Sizes.Small} onClick={() => void refreshUser()}>
                            I already verified
                        </Button.Text>
                    </div>
                </div>
            </Alert>
        </PageContentBlock>
    );
};

export default EmailVerificationGate;
