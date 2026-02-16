import { Route, Routes } from 'react-router-dom';
import EmailContainer from '@/components/admin/modules/email/EmailContainer';
import EmailActivityLog from '@/components/admin/modules/email/EmailActivityLog';
import DeferredQueueViewer from '@/components/admin/modules/email/DeferredQueueViewer';
import PageContentBlock from '@/elements/PageContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';
import ContentBox from '@/elements/ContentBox';
import ResendSettings from '@/components/admin/modules/email/ResendSettings';
import NotificationSettings from '@/components/admin/modules/email/NotificationSettings';
import SendTestEmail from '@/components/admin/modules/email/SendTestEmail';
import SendCustomEmail from '@/components/admin/modules/email/SendCustomEmail';

export default () => {
    return (
        <PageContentBlock title={'Email Management'}>
            <FlashMessageRender byKey={'email:resend'} />
            <FlashMessageRender byKey={'email:test'} />
            <FlashMessageRender byKey={'email:custom'} />
            <FlashMessageRender byKey={'email:notifications'} />
            <FlashMessageRender byKey={'email:activity'} />
            <FlashMessageRender byKey={'email:deferred'} />

            <SubNavigation>
                <SubNavigationLink to='/admin/email' name='Settings' base>
                    Settings
                </SubNavigationLink>
                <SubNavigationLink to='/admin/email/activity' name='Activity Log'>
                    Activity Log
                </SubNavigationLink>
                <SubNavigationLink to='/admin/email/queue' name='Deferred Queue'>
                    Deferred Queue
                </SubNavigationLink>
            </SubNavigation>

            <Routes>
                <Route
                    path='/'
                    element={
                        <>
                            <ContentBox title={'Resend Configuration'} showFlashes={'email:resend'}>
                                <ResendSettings />
                            </ContentBox>

                            <ContentBox
                                title={'Email Notification Settings'}
                                showFlashes={'email:notifications'}
                                css={'mt-8'}
                            >
                                <NotificationSettings />
                            </ContentBox>

                            <ContentBox title={'Test Email'} showFlashes={'email:test'} css={'mt-8'}>
                                <SendTestEmail />
                            </ContentBox>

                            <ContentBox title={'Send Custom Email'} showFlashes={'email:custom'} css={'mt-8'}>
                                <SendCustomEmail />
                            </ContentBox>
                        </>
                    }
                />
                <Route
                    path='/activity'
                    element={
                        <ContentBox title={'Email Activity Log'} showFlashes={'email:activity'}>
                            <EmailActivityLog />
                        </ContentBox>
                    }
                />
                <Route
                    path='/queue'
                    element={
                        <ContentBox title={'Deferred Email Queue'} showFlashes={'email:deferred'}>
                            <DeferredQueueViewer />
                        </ContentBox>
                    }
                />
            </Routes>
        </PageContentBlock>
    );
};
