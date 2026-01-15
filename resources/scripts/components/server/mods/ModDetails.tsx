import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import styled from 'styled-components';
import Modal from '@/elements/Modal';
import { type CurseForgeMod, type CurseForgeFile, getModFiles } from '@/api/routes/server/mods';
import { ServerContext } from '@/state/server';
import Spinner from '@/elements/Spinner';
import { httpErrorToHuman } from '@/api/http';
import useFlash from '@/plugins/useFlash';
import ModDownloadButton from './ModDownloadButton';
import FadeTransition from '@/elements/transitions/FadeTransition';

interface Props {
    mod: CurseForgeMod;
    onClose: () => void;
}

const ModalContent = styled.div`
    ${tw`text-white`}
`;

const ModHeader = styled.div`
    ${tw`flex items-start gap-4 mb-6`}
`;

const ModLogo = styled.img`
    ${tw`w-24 h-24 rounded object-cover flex-shrink-0`}
`;

const ModTitle = styled.h2`
    ${tw`text-2xl font-bold mb-2`}
`;

const ModAuthors = styled.p`
    ${tw`text-neutral-300 text-sm mb-2`}
`;

const ModStats = styled.div`
    ${tw`flex gap-4 text-sm text-neutral-400`}
`;

const ModSummary = styled.p`
    ${tw`text-neutral-300 mb-6`}
`;

const Section = styled.div`
    ${tw`mb-6`}
`;

const SectionTitle = styled.h3`
    ${tw`text-lg font-semibold mb-3`}
`;

const FileList = styled.div`
    ${tw`space-y-2`}
`;

const FileItem = styled.div`
    ${tw`bg-neutral-700 rounded p-3 flex items-center justify-between`}
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

export default ({ mod, onClose }: Props) => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const { addError } = useFlash();

    const [files, setFiles] = useState<CurseForgeFile[]>([]);
    const [loading, setLoading] = useState(true);
    const [showAllFiles, setShowAllFiles] = useState(false);

    useEffect(() => {
        setLoading(true);
        getModFiles(uuid, mod.id, { pageSize: 20, index: 0 })
            .then(response => {
                setFiles(response.data);
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'mods', message: httpErrorToHuman(error) });
            })
            .finally(() => setLoading(false));
    }, [uuid, mod.id]);

    const displayFiles = showAllFiles ? files : files.slice(0, 5);

    return (
        <Modal visible onDismissed={onClose}>
            <ModalContent>
                <ModHeader>
                    <ModLogo
                        src={mod.logo?.url || '/assets/images/placeholder-mod.png'}
                        alt={mod.name}
                        onError={(e) => {
                            e.currentTarget.src = '/assets/images/placeholder-mod.png';
                        }}
                    />
                    <div css={tw`flex-1`}>
                        <ModTitle>{mod.name}</ModTitle>
                        <ModAuthors>
                            By {mod.authors.map(a => a.name).join(', ') || 'Unknown'}
                        </ModAuthors>
                        <ModStats>
                            <span>{mod.downloadCount.toLocaleString()} downloads</span>
                            <span>•</span>
                            <span>
                                Updated {new Date(mod.dateModified).toLocaleDateString()}
                            </span>
                        </ModStats>
                    </div>
                </ModHeader>

                <ModSummary>{mod.summary}</ModSummary>

                <Section>
                    <SectionTitle>Available Files</SectionTitle>
                    {loading ? (
                        <div css={tw`flex justify-center py-6`}>
                            <Spinner />
                        </div>
                    ) : files.length > 0 ? (
                        <FadeTransition duration="duration-150" show>
                            <FileList>
                                {displayFiles.map(file => (
                                    <FileItem key={file.id}>
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
                                                <span>
                                                    {new Date(file.fileDate).toLocaleDateString()}
                                                </span>
                                                {' • '}
                                                <span>
                                                    {(file.fileLength / 1024 / 1024).toFixed(2)} MB
                                                </span>
                                            </FileDetails>
                                        </FileInfo>
                                        <ModDownloadButton
                                            modId={mod.id}
                                            fileId={file.id}
                                            fileName={file.fileName}
                                        />
                                    </FileItem>
                                ))}
                            </FileList>
                            {files.length > 5 && !showAllFiles && (
                                <button
                                    onClick={() => setShowAllFiles(true)}
                                    css={tw`mt-3 text-sm text-neutral-400 hover:text-neutral-200 transition-colors`}
                                >
                                    Show {files.length - 5} more files...
                                </button>
                            )}
                        </FadeTransition>
                    ) : (
                        <p css={tw`text-neutral-400 text-center py-6`}>
                            No files available for download.
                        </p>
                    )}
                </Section>

                {mod.screenshots && mod.screenshots.length > 0 && (
                    <Section>
                        <SectionTitle>Screenshots</SectionTitle>
                        <div css={tw`grid grid-cols-2 gap-2`}>
                            {mod.screenshots.slice(0, 4).map((screenshot, idx) => (
                                <img
                                    key={screenshot.id || idx}
                                    src={screenshot.thumbnailUrl}
                                    alt={screenshot.title}
                                    css={tw`rounded cursor-pointer hover:opacity-80 transition-opacity`}
                                    onClick={() => window.open(screenshot.url, '_blank')}
                                />
                            ))}
                        </div>
                    </Section>
                )}
            </ModalContent>
        </Modal>
    );
};
