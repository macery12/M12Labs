import { useEffect } from 'react';
import { Route, Routes, useParams } from 'react-router-dom';
import tw from 'twin.macro';

import ServerManageContainer from '@admin/management/servers/manage/ServerManageContainer';
import ServerStartupContainer from '@admin/management/servers/ServerStartupContainer';
import AdminContentBlock from '@/elements/AdminContentBlock';
import Spinner from '@/elements/Spinner';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';
import ServerSettingsContainer from '@admin/management/servers/ServerSettingsContainer';
import useFlash from '@/plugins/useFlash';
import { useServerFromRoute } from '@/api/routes/admin/server';
import {
    AdjustmentsIcon,
    CogIcon,
    CurrencyDollarIcon,
    DatabaseIcon,
    ExternalLinkIcon,
    InformationCircleIcon,
    ServerIcon,
    ShieldExclamationIcon,
    ClipboardCopyIcon,
} from '@heroicons/react/outline';
import { useStoreState } from '@/state/hooks';
import ServerDatabases from './ServerDatabases';
import ServerBillingContainer from './billing/ServerBillingContainer';
import Pill from '@/elements/Pill';
import CopyOnClick from '@/elements/CopyOnClick';

export default () => {
    const params = useParams<'id'>();

    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { billing } = useStoreState(state => state.everest.data!);
    const { data: server, error, isValidating, mutate } = useServerFromRoute();

    useEffect(() => {
        mutate();
    }, []);

    useEffect(() => {
        if (!error) clearFlashes('server');
        if (error) clearAndAddHttpError({ key: 'server', error });
    }, [error]);

    if (!server || (error && isValidating)) {
        return (
            <AdminContentBlock showFlashKey={'server'}>
                <Spinner size={'large'} centered />
            </AdminContentBlock>
        );
    }

    return (
        <AdminContentBlock title={'Server - ' + server.name}>
            <FlashMessageRender byKey={'backups'} css={tw`mb-4`} />
            <div css={tw`w-full flex flex-col mb-6`}>
                {/* Primary line - Server Name + Status */}
                <div css={tw`flex flex-row items-center mb-2`}>
                    <h2 css={tw`text-3xl text-neutral-50 font-header font-bold flex-shrink`} style={{ minWidth: '0' }}>
                        {server.name}
                    </h2>
                    <Pill type={'success'} css={tw`ml-3`}>
                        <InformationCircleIcon className={'mr-1 w-3'} /> {server.status ?? 'Active'}
                    </Pill>
                    <div className={'ml-auto flex items-center space-x-2'}>
                        {billing.enabled && server.billingProductId && (
                            <Pill type={'info'}>
                                <CurrencyDollarIcon className={'mr-1 w-3'} /> Billable
                            </Pill>
                        )}
                    </div>
                </div>

                {/* Secondary line - UUID + Primary Allocation */}
                <div
                    css={tw`flex flex-col md:flex-row md:items-center md:space-x-6 text-sm text-neutral-400 space-y-1 md:space-y-0`}
                >
                    <CopyOnClick text={server.uuid} showInNotification={true}>
                        <div css={tw`flex items-center cursor-pointer hover:text-neutral-300 transition-colors`}>
                            <span css={tw`font-mono mr-1.5`}>{server.uuid}</span>
                            <ClipboardCopyIcon css={tw`w-4 h-4`} />
                        </div>
                    </CopyOnClick>
                    <CopyOnClick text={server.relationships.allocations[0]?.getDisplayText()} showInNotification={true}>
                        <div css={tw`flex items-center cursor-pointer hover:text-neutral-300 transition-colors`}>
                            <ServerIcon className={'mr-1.5 h-4 w-4'} />
                            <span css={tw`font-mono mr-1.5`}>
                                {server.relationships.allocations[0]?.getDisplayText()}
                            </span>
                            <ClipboardCopyIcon css={tw`w-4 h-4`} />
                        </div>
                    </CopyOnClick>
                </div>
            </div>

            <FlashMessageRender byKey={'server'} css={tw`mb-4`} />

            <SubNavigation>
                <SubNavigationLink to={`/admin/servers/${params.id}`} name={'Settings'} icon={CogIcon} base />
                <SubNavigationLink to={`/admin/servers/${params.id}/startup`} name={'Startup'} icon={AdjustmentsIcon} />
                <SubNavigationLink
                    to={`/admin/servers/${params.id}/databases`}
                    name={'Databases'}
                    icon={DatabaseIcon}
                />
                <SubNavigationLink
                    to={`/admin/servers/${params.id}/billing`}
                    name={'Billing'}
                    icon={CurrencyDollarIcon}
                    disabled={!billing.enabled || !server.billingProductId}
                />
                <SubNavigationLink
                    to={`/admin/servers/${params.id}/manage`}
                    name={'Manage'}
                    icon={ShieldExclamationIcon}
                />
                <SubNavigationLink
                    to={`/server/${server.uuid.split('-')[0]}`}
                    name={'View as user'}
                    icon={ExternalLinkIcon}
                />
            </SubNavigation>

            <Routes>
                <Route path={''} element={<ServerSettingsContainer />} />
                <Route path={'startup'} element={<ServerStartupContainer />} />
                <Route path={'databases'} element={<ServerDatabases />} />
                <Route path={'billing'} element={<ServerBillingContainer />} />
                <Route path={'manage'} element={<ServerManageContainer />} />
            </Routes>
        </AdminContentBlock>
    );
};
