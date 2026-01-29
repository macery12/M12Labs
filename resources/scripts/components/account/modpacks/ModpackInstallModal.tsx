import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components';
import Modal from '@/elements/Modal';
import { type CurseForgeModpack, type CurseForgeFile } from '@/api/routes/server/modpacks';
import {
    getModpackFiles,
    installModpackToServer,
    getServerModpackInfo,
    getCompatibleServers,
} from '@/api/routes/account/modpacks';
import Spinner from '@/elements/Spinner';
import { httpErrorToHuman } from '@/api/http';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import { Dialog } from '@/elements/dialog';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faServer, faExclamationTriangle, faExternalLinkAlt } from '@fortawesome/free-solid-svg-icons';

const PLACEHOLDER_IMAGE = '/assets/images/placeholder-mod.png';

interface Props {
    modpack: CurseForgeModpack;
    onClose: () => void;
}

const ModalContent = styled.div`
    ${tw`text-white`}
`;

const ModpackHeader = styled.div`
    ${tw`flex items-start gap-4 mb-6`}
`;

const ModpackLogo = styled.img`
    ${tw`w-24 h-24 rounded object-cover flex-shrink-0`}
`;

const ModpackTitle = styled.h2`
    ${tw`text-2xl font-bold mb-2`}
`;

const ModpackAuthors = styled.p`
    ${tw`text-neutral-300 text-sm mb-2`}
`;

const ModpackStats = styled.div`
    ${tw`flex gap-4 text-sm text-neutral-400`}
`;

const ModpackSummary = styled.p`
    ${tw`text-neutral-300 mb-6`}
`;

const Section = styled.div`
    ${tw`mb-6`}
`;

const SectionTitle = styled.h3`
    ${tw`text-lg font-semibold mb-3`}
`;

const FileList = styled.div`
    ${tw`space-y-2 max-h-64 overflow-y-auto`}
`;

const FileItem = styled.div<{ $selected?: boolean }>`
    ${tw`bg-neutral-700 rounded p-3 cursor-pointer border-2 transition-all`}
    ${props =>
        props.$selected ? tw`border-neutral-500 bg-neutral-600` : tw`border-transparent hover:border-neutral-600`}
`;

const FileInfo = styled.div`
    ${tw`flex-1`}
`;

const FileName = styled.p`
    ${tw`text-white font-medium mb-1`}
`;

const FileDetails = styled.p`
    ${tw`text-xs text-neutral-400`}
`;

const ServerItem = styled.div<{ $selected?: boolean }>`
    ${tw`bg-neutral-700 rounded p-3 cursor-pointer border-2 transition-all flex items-center gap-3`}
    ${props =>
        props.$selected ? tw`border-neutral-500 bg-neutral-600` : tw`border-transparent hover:border-neutral-600`}
`;

const ServerList = styled.div`
    ${tw`space-y-2 max-h-64 overflow-y-auto`}
`;

const getReleaseTypeLabel = (type: number) => {
    switch (type) {
        case 1:
            return 'Release';
        case 2:
            return 'Beta';
        case 3:
            return 'Alpha';
        default:
            return 'Unknown';
    }
};

const getReleaseTypeColor = (type: number) => {
    switch (type) {
        case 1:
            return tw`text-green-400`;
        case 2:
            return tw`text-yellow-400`;
        case 3:
            return tw`text-red-400`;
        default:
            return tw`text-neutral-400`;
    }
};

export default ({ modpack, onClose }: Props) => {
    const { addFlash, addError } = useFlash();

    const [files, setFiles] = useState<CurseForgeFile[]>([]);
    const [servers, setServers] = useState<Array<{ uuid: string; name: string; eggId: number }>>([]);
    const [loading, setLoading] = useState(true);
    const [selectedFile, setSelectedFile] = useState<CurseForgeFile | null>(null);
    const [selectedServer, setSelectedServer] = useState<{ uuid: string; name: string; eggId: number } | null>(null);
    const [installing, setInstalling] = useState(false);
    const [showConfirmDialog, setShowConfirmDialog] = useState(false);
    const [serverModpackInfo, setServerModpackInfo] = useState<
        Record<string, { projectId?: string; versionId?: string; modpackName?: string }>
    >({});

    useEffect(() => {
        setLoading(true);

        Promise.all([getModpackFiles(modpack.id, { pageSize: 20, index: 0 }), getCompatibleServers()])
            .then(([filesResponse, compatibleServersResponse]) => {
                setFiles(filesResponse.data);
                setServers(compatibleServersResponse.servers);

                // Load current modpack info for each server
                compatibleServersResponse.servers.forEach(server => {
                    getServerModpackInfo(server.uuid)
                        .then(info => {
                            setServerModpackInfo(prev => ({
                                ...prev,
                                [server.uuid]: info,
                            }));
                        })
                        .catch(() => {
                            // Ignore errors for individual servers
                        });
                });
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'account:modpacks', message: httpErrorToHuman(error) });
            })
            .finally(() => setLoading(false));
    }, [modpack.id]);

    const handleInstall = () => {
        if (!selectedServer || !selectedFile) return;

        setInstalling(true);
        installModpackToServer({
            serverId: selectedServer.uuid,
            modpackId: modpack.id,
            fileId: selectedFile.id,
        })
            .then(() => {
                addFlash({
                    key: 'account:modpacks',
                    type: 'success',
                    message: `Modpack "${modpack.name}" is being installed to server "${selectedServer.name}". The server will be reinstalled automatically.`,
                });
                onClose();
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'account:modpacks', message: httpErrorToHuman(error) });
            })
            .finally(() => {
                setInstalling(false);
                setShowConfirmDialog(false);
            });
    };

    const handleInstallClick = () => {
        if (!selectedServer || !selectedFile) return;
        setShowConfirmDialog(true);
    };

    return (
        <>
            <Modal visible onDismissed={onClose} large>
                <ModalContent>
                    <ModpackHeader>
                        <ModpackLogo
                            src={modpack.logo?.url || PLACEHOLDER_IMAGE}
                            alt={modpack.name}
                            onError={e => {
                                e.currentTarget.src = PLACEHOLDER_IMAGE;
                            }}
                        />
                        <div css={tw`flex-1`}>
                            <div css={tw`flex items-center gap-2`}>
                                <ModpackTitle css={tw`mb-2`}>{modpack.name}</ModpackTitle>
                                <a
                                    href={modpack.links.websiteUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    css={tw`text-neutral-400 hover:text-neutral-200 transition-colors mb-2`}
                                    title="View on CurseForge"
                                    onClick={e => e.stopPropagation()}
                                >
                                    <FontAwesomeIcon icon={faExternalLinkAlt} css={tw`text-base`} />
                                </a>
                            </div>
                            <ModpackAuthors>
                                By {modpack.authors.map(a => a.name).join(', ') || 'Unknown'}
                            </ModpackAuthors>
                            <ModpackStats>
                                <span>{modpack.downloadCount.toLocaleString()} downloads</span>
                                <span>•</span>
                                <span>Updated {new Date(modpack.dateModified).toLocaleDateString()}</span>
                            </ModpackStats>
                        </div>
                    </ModpackHeader>

                    <ModpackSummary>{modpack.summary}</ModpackSummary>

                    {loading ? (
                        <div css={tw`flex justify-center py-6`}>
                            <Spinner />
                        </div>
                    ) : (
                        <>
                            <Section>
                                <SectionTitle>Select Server</SectionTitle>
                                {servers.length > 0 ? (
                                    <ServerList>
                                        {servers.map(server => {
                                            const currentModpack = serverModpackInfo[server.uuid];
                                            const isCurrentModpack =
                                                currentModpack?.projectId === modpack.id.toString();

                                            return (
                                                <ServerItem
                                                    key={server.uuid}
                                                    $selected={selectedServer?.uuid === server.uuid}
                                                    onClick={() => setSelectedServer(server)}
                                                >
                                                    <FontAwesomeIcon icon={faServer} css={tw`text-neutral-400`} />
                                                    <div css={tw`flex-1`}>
                                                        <div css={tw`font-medium text-neutral-100`}>{server.name}</div>
                                                        {isCurrentModpack && (
                                                            <div css={tw`text-xs text-green-400 mt-1`}>
                                                                Currently running this modpack
                                                            </div>
                                                        )}
                                                        {currentModpack?.modpackName && !isCurrentModpack && (
                                                            <div css={tw`text-xs text-neutral-400 mt-1`}>
                                                                Currently: {currentModpack.modpackName}
                                                            </div>
                                                        )}
                                                    </div>
                                                </ServerItem>
                                            );
                                        })}
                                    </ServerList>
                                ) : (
                                    <p css={tw`text-neutral-400 text-center py-6`}>
                                        No servers available with mods enabled. Enable mods on a server first.
                                    </p>
                                )}
                            </Section>

                            {selectedServer && (
                                <Section>
                                    <SectionTitle>Select Version</SectionTitle>
                                    {files.length > 0 ? (
                                        <FileList>
                                            {files.map(file => (
                                                <FileItem
                                                    key={file.id}
                                                    $selected={selectedFile?.id === file.id}
                                                    onClick={() => setSelectedFile(file)}
                                                >
                                                    <FileInfo>
                                                        <FileName>{file.displayName}</FileName>
                                                        <FileDetails>
                                                            <span css={getReleaseTypeColor(file.releaseType)}>
                                                                {getReleaseTypeLabel(file.releaseType)}
                                                            </span>
                                                            {' • '}
                                                            <span>
                                                                {file.gameVersions.slice(0, 3).join(', ')}
                                                                {file.gameVersions.length > 3 && '...'}
                                                            </span>
                                                            {' • '}
                                                            <span>{new Date(file.fileDate).toLocaleDateString()}</span>
                                                            {' • '}
                                                            <span>{(file.fileLength / 1024 / 1024).toFixed(2)} MB</span>
                                                        </FileDetails>
                                                    </FileInfo>
                                                </FileItem>
                                            ))}
                                        </FileList>
                                    ) : (
                                        <p css={tw`text-neutral-400 text-center py-6`}>
                                            No files available for download.
                                        </p>
                                    )}
                                </Section>
                            )}

                            <div css={tw`flex gap-3 mt-6 justify-end`}>
                                <Button.Text onClick={onClose}>Cancel</Button.Text>
                                <Button
                                    onClick={handleInstallClick}
                                    disabled={!selectedServer || !selectedFile || installing}
                                >
                                    {installing ? 'Installing...' : 'Install Modpack'}
                                </Button>
                            </div>
                        </>
                    )}
                </ModalContent>
            </Modal>

            <Dialog.Confirm
                open={showConfirmDialog}
                title={'Confirm Modpack Installation'}
                confirm={'Yes, install modpack'}
                onClose={() => setShowConfirmDialog(false)}
                onConfirmed={handleInstall}
            >
                <div css={tw`text-sm rounded-lg p-4 bg-yellow-500/25 mb-4`}>
                    <p css={tw`font-bold text-yellow-300 mb-2 flex items-center gap-2`}>
                        <FontAwesomeIcon icon={faExclamationTriangle} />
                        WARNING: This will overwrite server data
                    </p>
                    <p css={tw`mb-2`}>Installing this modpack will:</p>
                    <ul css={tw`list-disc list-inside space-y-1 ml-2`}>
                        <li>Update the server's environment variables</li>
                        <li>Trigger a full server reinstall</li>
                        <li>Download and install the modpack files</li>
                        <li>Replace existing mods and configurations</li>
                    </ul>
                </div>
                <p css={tw`mb-2`}>
                    <strong>Server:</strong> {selectedServer?.name}
                </p>
                <p css={tw`mb-2`}>
                    <strong>Modpack:</strong> {modpack.name}
                </p>
                <p>
                    <strong>Version:</strong> {selectedFile?.displayName}
                </p>
                <p css={tw`mt-4 text-neutral-400 text-sm`}>
                    Make sure you have backed up any important data before continuing.
                </p>
            </Dialog.Confirm>
        </>
    );
};
