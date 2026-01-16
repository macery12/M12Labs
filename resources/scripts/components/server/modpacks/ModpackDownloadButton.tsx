import { useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import { Button } from '@/elements/button';
import { downloadModpack } from '@/api/routes/server/modpacks';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDownload, faCheck } from '@fortawesome/free-solid-svg-icons';
import Spinner from '@/elements/Spinner';

interface Props {
    modpackId: number;
    fileId: number;
    fileName: string;
    modpackName: string;
    onDownloadStart?: () => void;
    onDownloadComplete?: () => void;
}

export default ({ modpackId, fileId, fileName, modpackName, onDownloadStart, onDownloadComplete }: Props) => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const { addFlash, addError } = useFlash();

    const [downloading, setDownloading] = useState(false);
    const [downloaded, setDownloaded] = useState(false);

    const handleDownload = () => {
        setDownloading(true);
        
        // Notify parent that download is starting - this will switch to status view
        if (onDownloadStart) {
            onDownloadStart();
        }
        
        downloadModpack(uuid, modpackId, fileId)
            .then(() => {
                setDownloaded(true);
                setDownloading(false);
                addFlash({
                    key: 'modpacks',
                    type: 'success',
                    message: `Modpack "${modpackName}" downloaded and installed successfully! All mods have been extracted to the /mods folder.`,
                });
                setTimeout(() => {
                    setDownloaded(false);
                    if (onDownloadComplete) {
                        onDownloadComplete();
                    }
                }, 2000);
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'modpacks', message: httpErrorToHuman(error) });
                setDownloading(false);
                // Also notify completion on error so UI can reset
                if (onDownloadComplete) {
                    onDownloadComplete();
                }
            });
    };

    return (
        <Button
            size={Button.Sizes.Small}
            onClick={handleDownload}
            disabled={downloading || downloaded}
            css={tw`flex items-center gap-2`}
        >
            {downloading ? (
                <>
                    <Spinner size={'small'} />
                    <span>Installing...</span>
                </>
            ) : downloaded ? (
                <>
                    <FontAwesomeIcon icon={faCheck} />
                    <span>Installed</span>
                </>
            ) : (
                <>
                    <FontAwesomeIcon icon={faDownload} />
                    <span>Install</span>
                </>
            )}
        </Button>
    );
};
