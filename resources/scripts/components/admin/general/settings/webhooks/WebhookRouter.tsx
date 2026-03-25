import { useStoreState } from '@/state/hooks';
import AdminContentBlock from '@/elements/AdminContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import EnableWebhooks from './EnableWebhooks';
import WebhookEventsContainer from './events/WebhookEventsContainer';
import { SubNavigation, SubNavigationLink } from '@/components/admin/SubNavigation';
import { ChipIcon, AdjustmentsIcon, LinkIcon } from '@heroicons/react/outline';

export default () => {
    const enabled = useStoreState(state => state.everest.data!.webhooks.enabled);

    if (!enabled) return <EnableWebhooks />;

    return (
        <AdminContentBlock title={'Webhooks'}>
            <FlashMessageRender byKey={'admin:webhooks'} className={'mb-4'} />
            <div className={'w-full flex flex-row items-center mb-8'}>
                <div className={'flex flex-col flex-shrink'} style={{ minWidth: '0' }}>
                    <h2 className={'text-2xl text-neutral-50 font-header font-medium'}>Webhooks</h2>
                    <p
                        className={
                            'hidden lg:block text-base text-neutral-400 whitespace-nowrap overflow-ellipsis overflow-hidden'
                        }
                    >
                        Change settings for realtime webhook monitoring.
                    </p>
                </div>
            </div>
            <SubNavigation>
                <SubNavigationLink to="/admin/settings" name="Core" base>
                    <ChipIcon />
                </SubNavigationLink>
                <SubNavigationLink to="/admin/settings/mode" name="Modes">
                    <AdjustmentsIcon />
                </SubNavigationLink>
                <SubNavigationLink to="/admin/settings/webhooks" name="Webhooks">
                    <LinkIcon />
                </SubNavigationLink>
            </SubNavigation>
            <WebhookEventsContainer />
        </AdminContentBlock>
    );
};
