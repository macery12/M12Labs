import ContentBox from '@/elements/ContentBox';
import PageContentBlock from '@/elements/PageContentBlock';
import ResendSettings from '@/components/admin/modules/email/ResendSettings';
import NotificationSettings from '@/components/admin/modules/email/NotificationSettings';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';

export default () => {
    return (
        <PageContentBlock title={'Email Management'}>
            <SubNavigation>
                <SubNavigationLink to='/admin/email' name='Settings' base>
                    Settings
                </SubNavigationLink>
                <SubNavigationLink to='/admin/email/activity' name='Activity Log'>
                    Activity Log
                </SubNavigationLink>
            </SubNavigation>

            <ContentBox title={'Email Configuration'} showFlashes={'email:settings'}>
                <ResendSettings />
            </ContentBox>

            <ContentBox title={'Email Notification Settings'} showFlashes={'email:notifications'} css={'mt-8'}>
                <NotificationSettings />
            </ContentBox>

        </PageContentBlock>
    );
};
