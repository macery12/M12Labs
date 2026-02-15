import ContentBox from '@/elements/ContentBox';
import PageContentBlock from '@/elements/PageContentBlock';
import ResendSettings from '@/components/admin/modules/email/ResendSettings';
import NotificationSettings from '@/components/admin/modules/email/NotificationSettings';
import SendTestEmail from '@/components/admin/modules/email/SendTestEmail';
import SendCustomEmail from '@/components/admin/modules/email/SendCustomEmail';
import FlashMessageRender from '@/elements/FlashMessageRender';

export default () => {
    return (
        <PageContentBlock title={'Email Settings'}>
            <FlashMessageRender byKey={'email:resend'} />
            <FlashMessageRender byKey={'email:test'} />
            <FlashMessageRender byKey={'email:custom'} />
            <FlashMessageRender byKey={'email:notifications'} />

            <ContentBox title={'Resend Configuration'} showFlashes={'email:resend'}>
                <ResendSettings />
            </ContentBox>

            <ContentBox title={'Email Notification Settings'} showFlashes={'email:notifications'} css={'mt-8'}>
                <NotificationSettings />
            </ContentBox>

            <ContentBox title={'Test Email'} showFlashes={'email:test'} css={'mt-8'}>
                <SendTestEmail />
            </ContentBox>

            <ContentBox title={'Send Custom Email'} showFlashes={'email:custom'} css={'mt-8'}>
                <SendCustomEmail />
            </ContentBox>
        </PageContentBlock>
    );
};
