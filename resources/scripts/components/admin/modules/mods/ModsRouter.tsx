import { useStoreState } from '@/state/hooks';
import { Route, Routes } from 'react-router-dom';
import { CogIcon, DatabaseIcon } from '@heroicons/react/outline';
import AdminContentBlock from '@/elements/AdminContentBlock';
import { NotFound } from '@/elements/ScreenBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';
import EnableMods from '@admin/modules/mods/EnableMods';
import OverviewContainer from '@admin/modules/mods/OverviewContainer';
import SettingsContainer from './SettingsContainer';

export default () => {
    const settings = useStoreState(state => state.everest.data!.mods);

    if (!settings.enabled) return <EnableMods />;

    return (
        <AdminContentBlock title={'Mods'}>
            <FlashMessageRender byKey={'admin:mods'} className={'mb-4'} />
            <div className={'mb-8 flex w-full flex-row items-center'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Mods</h2>
                    <p
                        className={
                            'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                        }
                    >
                        Integrate CurseForge for Minecraft mod management.
                    </p>
                </div>
            </div>
            <SubNavigation>
                <SubNavigationLink to={'/admin/mods'} name={'Overview'} base>
                    <DatabaseIcon />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/mods/settings'} name={'Settings'}>
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
