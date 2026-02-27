import { useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import { Button } from '@/elements/button';
import { downloadMod } from '@/api/routes/server/mods';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import Spinner from '@/elements/Spinner';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDownload, faCheck } from '@fortawesome/free-solid-svg-icons';
import Can from '@/elements/Can';

interface Props {
    modId: number | string;
    fileId: number | string;
    fileName: string;
    source: string;
    disabledReason?: string | null;
}

export default ({ modId, fileId, fileName, source, disabledReason }: Props) => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const { addFlash, addError } = useFlash();

    const [downloading, setDownloading] = useState(false);
    const [downloaded, setDownloaded] = useState(false);

    const handleDownload = () => {
        setDownloading(true);
        setDownloaded(false);

        downloadMod(uuid, modId, fileId, source)
            .then(data => {
                const resolvedName = data?.file?.name || fileName;
                setDownloaded(true);
                addFlash({
                    key: 'mods',
                    type: 'success',
                    message: `Successfully downloaded ${resolvedName} to ${source === 'spigot' ? '/plugins' : '/mods'} directory.`,
                });
                setTimeout(() => setDownloaded(false), 3000);
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'mods', message: httpErrorToHuman(error) });
            })
            .finally(() => setDownloading(false));
    };

    return (
        <Can action={'file.create'}>
            <Button
                size={Button.Sizes.Small}
                onClick={handleDownload}
                disabled={downloading || downloaded || Boolean(disabledReason)}
                css={[downloaded && tw`bg-green-600 hover:bg-green-700`, tw`min-w-[100px]`]}
                >
                {downloading ? (
                    <>
                        <Spinner size={'small'} css={tw`mr-2`} />
                        Downloading...
                    </>
                ) : downloaded ? (
                    <>
                        <FontAwesomeIcon icon={faCheck} css={tw`mr-2`} />
                        Downloaded
                    </>
                ) : disabledReason ? (
                    disabledReason
                ) : (
                    <>
                        <FontAwesomeIcon icon={faDownload} css={tw`mr-2`} />
                        Download
                    </>
                )}
            </Button>
        </Can>
    );
};
