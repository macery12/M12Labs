import { useStoreState } from '@/state/hooks';
import { Route, Routes } from 'react-router-dom';
import { CogIcon, SparklesIcon } from '@heroicons/react/outline';
import AdminContentBlock from '@/elements/AdminContentBlock';
import { NotFound } from '@/elements/ScreenBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';
import EnableAI from '@admin/modules/ai/EnableAI';
import OverviewContainer from '@admin/modules/ai/OverviewContainer';
import ConfigureAI from '@admin/modules/ai/ConfigureAI';
import SettingsContainer from './SettingsContainer';
import { updateSettings } from '@/api/routes/admin/ai/settings';

export default () => {
    const settings = useStoreState(state => state.everest.data!.ai);

    if (!settings.enabled) return <EnableAI />;

    // For Ollama mode, key is not required, only check for endpoint and model
    // For OpenAI mode, key is required
    const needsConfiguration =
        settings.mode === 'ollama'
            ? !settings.endpoint || !settings.model
            : !settings.key || !settings.endpoint || !settings.model;

    const handleDismissConfiguration = () => {
        // Disable AI when user dismisses configuration dialog
        updateSettings({ enabled: false })
            .then(() => {
                // @ts-expect-error this is fine
                window.location = '/admin/ai';
            })
            .catch(error => {
                console.error('Failed to disable AI:', error);
                // Still reload to let user try again
                // @ts-expect-error this is fine
                window.location = '/admin/ai';
            });
    };

    if (settings.enabled && needsConfiguration) return <ConfigureAI onDismiss={handleDismissConfiguration} />;

    return (
        <AdminContentBlock title={'Jexactyl AI'}>
            <FlashMessageRender byKey={'admin:ai'} className={'mb-4'} />
            <div className={'mb-8 flex w-full flex-row items-center'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Jexactyl AI</h2>
                    <p
                        className={
                            'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                        }
                    >
                        Use Artificial Intelligence to add more power to Jexactyl.
                    </p>
                </div>
            </div>
            <SubNavigation>
                <SubNavigationLink to={'/admin/ai'} name={'General'} base>
                    <SparklesIcon />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/ai/settings'} name={'Options'}>
                    <CogIcon />
                </SubNavigationLink>
            </SubNavigation>
            <Routes>
                <Route path={'/'} element={<OverviewContainer />} />
                <Route path={'/settings'} element={<SettingsContainer />} />

                <Route path={'/*'} element={<NotFound />} />
            </Routes>
        </AdminContentBlock>
    );
};
