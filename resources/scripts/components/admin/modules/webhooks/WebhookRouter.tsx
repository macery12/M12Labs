import { useStoreState } from '@/state/hooks';
import { Route, Routes } from 'react-router-dom';
import { NotFound } from '@/elements/ScreenBlock';
import { CalendarIcon, CogIcon } from '@heroicons/react/outline';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';
import AdminContentBlock from '@/elements/AdminContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import EnableWebhooks from './EnableWebhooks';
import WebhookSettings from './WebhookSettings';
import WebhookEventsContainer from './events/WebhookEventsContainer';

export default () => {
    const enabled = useStoreState(state => state.everest.data!.webhooks.enabled);

    if (!enabled) return <EnableWebhooks />;

    return (
        <AdminContentBlock title={'Webhooks'}>
            <FlashMessageRender byKey={'admin:webhooks'} className={'mb-4'} />
            <div className={'mb-8 flex w-full flex-row items-center'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Webhook Logging</h2>
                    <p
                        className={
                            'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                        }
                    >
                        Change settings for realtime webhook monitoring.
                    </p>
                </div>
            </div>
            <SubNavigation>
                <SubNavigationLink to={'/admin/webhooks'} name={'Settings'} base>
                    <CogIcon />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/webhooks/events'} name={'Events'}>
                    <CalendarIcon />
                </SubNavigationLink>
            </SubNavigation>
            <Routes>
                <Route path={'/'} element={<WebhookSettings />} />
                <Route path={'/events'} element={<WebhookEventsContainer />} />

                <Route path={'/*'} element={<NotFound />} />
            </Routes>
        </AdminContentBlock>
    );
};
