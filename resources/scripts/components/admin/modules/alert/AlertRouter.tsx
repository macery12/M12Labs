import { Route, Routes } from 'react-router-dom';
import AdminContentBlock from '@/elements/AdminContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { EyeIcon, ShieldExclamationIcon, ClipboardListIcon } from '@heroicons/react/outline';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';
import AlertSettings from './AlertSettings';
import AlertAppearance from './AlertAppearance';
import AlertList from './AlertList';
import AlertForm from './AlertForm';
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
            <SubNavigationLink to={'/admin/alerts'} name={'Manage Alerts'} base>
                <ClipboardListIcon />
            </SubNavigationLink>
            <SubNavigationLink to={'/admin/alerts/legacy'} name={'Legacy'} base>
                <ShieldExclamationIcon />
            </SubNavigationLink>
            <SubNavigationLink to={'/admin/alerts/legacy/view'} name={'Legacy Appearance'}>
                <EyeIcon />
            </SubNavigationLink>
        </SubNavigation>
        <Routes>
            <Route path={'/'} element={<AlertList />} />
            <Route path={'/create'} element={<AlertForm />} />
            <Route path={'/edit/:id'} element={<AlertForm />} />
            <Route path={'/legacy'} element={<AlertSettings />} />
            <Route path={'/legacy/view'} element={<AlertAppearance />} />

            <Route path={'/*'} element={<NotFound />} />
        </Routes>
    </AdminContentBlock>
);
