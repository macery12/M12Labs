import ContentBox from '@/elements/ContentBox';
import PageContentBlock from '@/elements/PageContentBlock';
import ResendSettings from '@/components/admin/modules/email/ResendSettings';
import SendTestEmail from '@/components/admin/modules/email/SendTestEmail';
import SendCustomEmail from '@/components/admin/modules/email/SendCustomEmail';
import FlashMessageRender from '@/elements/FlashMessageRender';

export default () => {
    return (
        <PageContentBlock title={'Email Settings'}>
            <FlashMessageRender byKey={'email:resend'} />
            <FlashMessageRender byKey={'email:test'} />
            <FlashMessageRender byKey={'email:custom'} />

            <ContentBox title={'Resend Configuration'} showFlashes={'email:resend'}>
                <ResendSettings />
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
