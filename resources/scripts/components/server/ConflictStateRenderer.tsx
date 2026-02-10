import ServerInstallSvg from '@/assets/images/server_installing.svg';
import ServerErrorSvg from '@/assets/images/server_error.svg';
import ServerRestoreSvg from '@/assets/images/server_restore.svg';
import ScreenBlock from '@/elements/ScreenBlock';
import { ServerContext } from '@/state/server';
import { useStoreState } from '@/state/hooks';
import { Button } from '@/elements/button';
import tw from 'twin.macro';
import { useNavigate } from 'react-router-dom';

export default () => {
    const status = ServerContext.useStoreState(state => state.server.data?.status || null);
    const isTransferring = ServerContext.useStoreState(state => state.server.data?.isTransferring || false);
    const isNodeUnderMaintenance = ServerContext.useStoreState(
        state => state.server.data?.isNodeUnderMaintenance || false,
    );
    const serverUuid = ServerContext.useStoreState(state => state.server.data?.uuid);
    const rootAdmin = useStoreState(state => state.user.data!.rootAdmin);
    const navigate = useNavigate();

    const handleAdminBypass = () => {
        if (!serverUuid) return;
        // Store bypass state in session storage
        sessionStorage.setItem(`admin_bypass_conflict_${serverUuid}`, 'true');
        // Navigate to force reload
        navigate(`/server/${serverUuid}`);
    };

    const renderAdminBypassButton = () => {
        if (!rootAdmin) return null;

        return (
            <div css={tw`mt-4`}>
                <Button onClick={handleAdminBypass} size={Button.Sizes.Large} variant={Button.Variants.Secondary}>
                    Admin Bypass
                </Button>
                <p css={tw`text-xs text-neutral-500 mt-2`}>
                    This will bypass the conflict screen without changing the server state.
                </p>
            </div>
        );
    };

    return status === 'installing' || status === 'install_failed' || status === 'reinstall_failed' ? (
        <ScreenBlock
            title={'Running Installer'}
            image={ServerInstallSvg}
            message={'Your server should be ready soon, please try again in a few minutes.'}
        >
            {renderAdminBypassButton()}
        </ScreenBlock>
    ) : status === 'suspended' ? (
        <ScreenBlock
            title={'Server Suspended'}
            image={ServerErrorSvg}
            message={'This server is suspended and cannot be accessed.'}
        >
            {renderAdminBypassButton()}
        </ScreenBlock>
    ) : isNodeUnderMaintenance ? (
        <ScreenBlock
            title={'Node under Maintenance'}
            image={ServerErrorSvg}
            message={'The node of this server is currently under maintenance.'}
        >
            {renderAdminBypassButton()}
        </ScreenBlock>
    ) : (
        <ScreenBlock
            title={isTransferring ? 'Transferring' : 'Restoring from Backup'}
            image={ServerRestoreSvg}
            message={
                isTransferring
                    ? 'Your server is being transferred to a new node, please check back later.'
                    : 'Your server is currently being restored from a backup, please check back in a few minutes.'
            }
        >
            {renderAdminBypassButton()}
        </ScreenBlock>
    );
};
