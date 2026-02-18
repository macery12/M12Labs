import { Alert } from '@/elements/alert';
import { Button } from '@/elements/button';
import { useEmailVerification } from '@/hooks/useEmailVerification';
import { useStoreState } from '@/state/hooks';
import FlashMessageRender from '@/elements/FlashMessageRender';
import tw from 'twin.macro';
import {
    EMAIL_VERIFICATION_ALERT_MESSAGE,
    EMAIL_VERIFICATION_FEATURES,
    formatRestrictedFeatures,
} from '@/constants/emailVerification';

interface Props {
    className?: string;
}

const EmailVerificationNotice = ({ className }: Props) => {
    const user = useStoreState(state => state.user.data!);
    const { resend, isCoolingDown, resendLabel, refreshUser } = useEmailVerification();

    if (user.emailVerified) {
        return null;
    }

    return (
        <div className={className}>
            <FlashMessageRender byKey={'account:verification'} />
            <Alert type="warning" className={'mb-4'}>
                <div css={tw`flex flex-col gap-3`}>
                    <div>
                        <strong className={'block text-lg'}>Verify your email to continue</strong>
                        <span className={'text-sm text-yellow-50'}>
                            {EMAIL_VERIFICATION_ALERT_MESSAGE} Restricted areas: {formatRestrictedFeatures(EMAIL_VERIFICATION_FEATURES)}.
                        </span>
                    </div>
                    <div css={tw`flex flex-wrap gap-2`}>
                        <Button size={Button.Sizes.Small} disabled={isCoolingDown} onClick={() => void resend()}>
                            {resendLabel}
                        </Button>
                        <Button.Text size={Button.Sizes.Small} onClick={() => void refreshUser()}>
                            I already verified
                        </Button.Text>
                    </div>
                </div>
            </Alert>
        </div>
    );
};

export default EmailVerificationNotice;
