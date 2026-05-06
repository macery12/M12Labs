import { useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import { getSshInfo, SshInfo } from '@/api/routes/server/wingsRs';
import useFlash from '@/plugins/useFlash';
import CopyOnClick from '@/elements/CopyOnClick';
import Input from '@/elements/Input';
import Label from '@/elements/Label';
import TitledGreyBox from '@/elements/TitledGreyBox';
import tw from 'twin.macro';
import { faTerminal } from '@fortawesome/free-solid-svg-icons';
import SpinnerOverlay from '@/elements/SpinnerOverlay';

export default () => {
    const [loading, setLoading] = useState(true);
    const [sshInfo, setSshInfo] = useState<SshInfo | null>(null);
    const { addError } = useFlash();

    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const isSupercharged = ServerContext.useStoreState(state => state.server.data!.isNodeSupercharged);

    useEffect(() => {
        if (!isSupercharged) {
            setLoading(false);
            return;
        }

        getSshInfo(uuid)
            .then(data => {
                setSshInfo(data);
                setLoading(false);
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'files', message: 'Failed to load SSH details.' });
                setLoading(false);
            });
    }, [uuid, isSupercharged]);

    if (!isSupercharged || !sshInfo) return null;

    const sshCommand = sshInfo.command || `ssh ${sshInfo.username}@${sshInfo.host} -p ${sshInfo.port}`;

    return (
        <TitledGreyBox title={'SSH Access'} icon={faTerminal} css={tw`mt-6`}>
            <SpinnerOverlay visible={loading} />
            <div>
                <Label>SSH Command</Label>
                <CopyOnClick text={sshCommand}>
                    <Input type={'text'} value={sshCommand} readOnly />
                </CopyOnClick>
            </div>
            <div css={tw`mt-4`}>
                <Label>Host</Label>
                <CopyOnClick text={sshInfo.host}>
                    <Input type={'text'} value={`${sshInfo.host}:${sshInfo.port}`} readOnly />
                </CopyOnClick>
            </div>
            <div css={tw`mt-4`}>
                <Label>Username</Label>
                <CopyOnClick text={sshInfo.username}>
                    <Input type={'text'} value={sshInfo.username} readOnly />
                </CopyOnClick>
            </div>
            {sshInfo.container_supported && (
                <div css={tw`mt-4 border-l-4 border-green-500 p-3`}>
                    <p css={tw`text-xs text-neutral-200`}>
                        This node supports direct container SSH access. You can connect directly to your server&apos;s
                        container via SSH.
                    </p>
                </div>
            )}
            <div css={tw`mt-4 border-l-4 border-cyan-500 p-3`}>
                <p css={tw`text-xs text-neutral-200`}>
                    SSH access is provided by Wings-RS. Use your panel password to authenticate.
                </p>
            </div>
        </TitledGreyBox>
    );
};
