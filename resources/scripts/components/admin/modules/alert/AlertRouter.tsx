import { Route, Routes } from 'react-router-dom';
import AdminContentBlock from '@/elements/AdminContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { EyeIcon, ShieldExclamationIcon } from '@heroicons/react/outline';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';
import AlertSettings from './AlertSettings';
import AlertAppearance from './AlertAppearance';
import { NotFound } from '@/elements/ScreenBlock';

export default () => (
    <AdminContentBlock title={'Alerts'}>
        <FlashMessageRender byKey={'admin:alert'} className={'mb-4'} />
        <div className={'mb-8 flex w-full flex-row items-center'}>
            <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Panel Alerts</h2>
                <p
                    className={
                        'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                    }
                >
                    Send warning and information alerts to your users.
                </p>
            </div>
        </div>
        <SubNavigation>
            <SubNavigationLink to={'/admin/alerts'} name={'General'} base>
                <ShieldExclamationIcon />
            </SubNavigationLink>
            <SubNavigationLink to={'/admin/alerts/view'} name={'Appearance'}>
                <EyeIcon />
            </SubNavigationLink>
        </SubNavigation>
        <Routes>
            <Route path={'/'} element={<AlertSettings />} />
            <Route path={'/view'} element={<AlertAppearance />} />

            <Route path={'/*'} element={<NotFound />} />
        </Routes>
    </AdminContentBlock>
);
