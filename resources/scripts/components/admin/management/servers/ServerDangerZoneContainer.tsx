import tw from 'twin.macro';
import ReinstallServerBox from '@admin/management/servers/manage/ReinstallServerBox';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { useEffect } from 'react';
import useFlash from '@/plugins/useFlash';
import ToggleInstallStatusBox from '@admin/management/servers/manage/ToggleInstallStatusBox';
import { useServerFromRoute } from '@/api/routes/admin/server';
import SuspendServerBox from './manage/SuspendServerBox';
import UnsuspendServerBox from './manage/UnsuspendServerBox';
import TransferServerBox from './manage/TransferServerBox';
import ServerDeleteButton from './ServerDeleteButton';
import { useStoreState } from '@/state/hooks';

export default () => {
    const { data: server } = useServerFromRoute();
    const { clearFlashes } = useFlash();
    const { secondary } = useStoreState(state => state.theme.data!.colors);

    if (!server) return null;

    useEffect(() => {
        clearFlashes('server:manage');
    }, []);

    return (
        <>
            {/* Warning banner */}
            <div css={tw`mb-6 rounded-lg border-2 border-red-500 bg-red-500 bg-opacity-10 p-4`}>
                <div css={tw`flex items-start`}>
                    <div css={tw`flex-shrink-0`}>
                        <svg
                            css={tw`h-6 w-6 text-red-400`}
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                            />
                        </svg>
                    </div>
                    <div css={tw`ml-3`}>
                        <h3 css={tw`text-sm font-medium text-red-300`}>Danger Zone</h3>
                        <div css={tw`mt-2 text-sm text-red-200`}>
                            <p>
                                The actions below can have serious consequences. Please proceed with caution and
                                ensure you understand what each action does before executing it.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <FlashMessageRender byKey={'server:manage'} className={'mb-4'} />

            <div css={tw`grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-4 gap-y-4 mb-8`}>
                <ReinstallServerBox />
                <ToggleInstallStatusBox />
                {server.status === 'suspended' ? <UnsuspendServerBox /> : <SuspendServerBox />}
                <TransferServerBox />
            </div>

            {/* Delete Server Section */}
            <div
                css={tw`mt-8 rounded-lg border-2 border-red-600 bg-red-600 bg-opacity-10 p-6`}
            >
                <h3 css={tw`text-lg font-medium text-red-300 mb-2`}>Delete Server</h3>
                <p css={tw`text-sm text-red-200 mb-4`}>
                    Permanently delete this server and all of its data. This action cannot be undone.
                </p>
                <ServerDeleteButton />
            </div>
        </>
    );
};
