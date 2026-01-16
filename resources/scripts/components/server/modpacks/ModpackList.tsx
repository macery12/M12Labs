import tw from 'twin.macro';
import styled from 'styled-components';
import { type CurseForgeModpack } from '@/api/routes/server/modpacks';
import Spinner from '@/elements/Spinner';
import { Button } from '@/elements/button';
import FadeTransition from '@/elements/transitions/FadeTransition';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faAngleDoubleLeft, faAngleDoubleRight, faDownload } from '@fortawesome/free-solid-svg-icons';

const PLACEHOLDER_IMAGE = '/assets/images/placeholder-mod.png';

interface Props {
    modpacks: CurseForgeModpack[];
    loading: boolean;
    pagination: {
        index: number;
        pageSize: number;
        resultCount: number;
        totalCount: number;
    };
    onModpackClick: (modpack: CurseForgeModpack) => void;
    onPageChange: (index: number) => void;
}

const ModpackCard = styled.div`
    ${tw`bg-neutral-800 rounded border border-neutral-700 cursor-pointer transition-colors duration-150`}

    &:hover {
        ${tw`bg-neutral-700 border-neutral-600`}
    }
`;

const ModpackHeader = styled.div`
    ${tw`flex items-start gap-3 p-3 border-b border-neutral-700/50`}
`;

const ModpackIcon = styled.img`
    ${tw`w-12 h-12 rounded flex-shrink-0 bg-neutral-900`}
`;

const ModpackInfo = styled.div`
    ${tw`flex-1 min-w-0`}
`;

const ModpackName = styled.h3`
    ${tw`text-sm font-semibold text-neutral-100 truncate mb-0.5`}
`;

const ModpackAuthor = styled.div`
    ${tw`text-xs text-neutral-400 truncate`}
`;

const ModpackStats = styled.div`
    ${tw`flex items-center gap-3 px-3 py-2 text-xs`}
`;

const StatItem = styled.div`
    ${tw`flex items-center gap-1.5 text-neutral-400`}
`;

const StatLabel = styled.span`
    ${tw`text-neutral-500`}
`;

const StatValue = styled.span`
    ${tw`text-neutral-300 font-medium`}
`;

const PaginationButton = styled(Button)`
    ${tw`p-0 w-10 h-10`}

    &:not(:last-of-type) {
        ${tw`mr-2`};
    }
`;

export default ({ modpacks, loading, pagination, onModpackClick, onPageChange }: Props) => {
    if (!modpacks.length && !loading) {
        return (
            <div css={tw`text-center py-16`}>
                <p css={tw`text-neutral-400 text-lg`}>No modpacks found matching your search criteria.</p>
            </div>
        );
    }

    const totalPages = Math.ceil(pagination.totalCount / pagination.pageSize);
    const currentPage = Math.floor(pagination.index / pagination.pageSize) + 1;
    const isFirstPage = currentPage === 1;
    const isLastPage = currentPage >= totalPages;

    const pages = [];
    const start = Math.max(currentPage - 2, 1);
    const end = Math.min(totalPages, currentPage + 2);

    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    const handlePageClick = (pageNumber: number) => {
        const newIndex = (pageNumber - 1) * pagination.pageSize;
        onPageChange(newIndex);
    };

    return (
        <div css={tw`mt-6`}>
            <FadeTransition duration="duration-150" show={!loading}>
                <div css={tw`grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-3`}>
                    {modpacks.map(modpack => {
                        const latestFile = modpack.latestFiles?.[0];
                        const gameVersion = latestFile?.gameVersions?.[0] || 'Unknown';
                        const downloadCount =
                            modpack.downloadCount >= 1000000
                                ? `${(modpack.downloadCount / 1000000).toFixed(1)}M`
                                : modpack.downloadCount >= 1000
                                ? `${(modpack.downloadCount / 1000).toFixed(0)}K`
                                : modpack.downloadCount.toString();

                        return (
                            <ModpackCard key={modpack.id} onClick={() => onModpackClick(modpack)}>
                                <ModpackHeader>
                                    <ModpackIcon
                                        src={modpack.logo?.thumbnailUrl || PLACEHOLDER_IMAGE}
                                        alt={modpack.name}
                                        onError={e => {
                                            e.currentTarget.src = PLACEHOLDER_IMAGE;
                                        }}
                                    />
                                    <ModpackInfo>
                                        <ModpackName title={modpack.name}>{modpack.name}</ModpackName>
                                        <ModpackAuthor title={modpack.authors[0]?.name || 'Unknown'}>
                                            by {modpack.authors[0]?.name || 'Unknown'}
                                        </ModpackAuthor>
                                    </ModpackInfo>
                                </ModpackHeader>
                                <ModpackStats>
                                    <StatItem>
                                        <StatLabel>Version:</StatLabel>
                                        <StatValue>{gameVersion}</StatValue>
                                    </StatItem>
                                    <StatItem css={tw`ml-auto`}>
                                        <FontAwesomeIcon icon={faDownload} css={tw`text-neutral-500`} />
                                        <StatValue>{downloadCount}</StatValue>
                                    </StatItem>
                                </ModpackStats>
                            </ModpackCard>
                        );
                    })}
                </div>

                {loading && (
                    <div css={tw`mt-6 flex justify-center`}>
                        <Spinner />
                    </div>
                )}

                {totalPages > 1 && (
                    <div css={tw`my-6 flex justify-center`}>
                        {!isFirstPage && pages[0] > 1 && (
                            <PaginationButton.Text
                                size={Button.Sizes.Small}
                                onClick={() => handlePageClick(1)}
                                className={'mx-1'}
                            >
                                <FontAwesomeIcon icon={faAngleDoubleLeft} />
                            </PaginationButton.Text>
                        )}
                        {pages.map(i => (
                            <PaginationButton.Text
                                size={Button.Sizes.Small}
                                className={'mx-1'}
                                key={`page_${i}`}
                                onClick={() => handlePageClick(i)}
                                disabled={i === currentPage}
                            >
                                {i}
                            </PaginationButton.Text>
                        ))}
                        {!isLastPage && pages[pages.length - 1] < totalPages && (
                            <PaginationButton.Text
                                size={Button.Sizes.Small}
                                onClick={() => handlePageClick(totalPages)}
                                className={'mx-1'}
                            >
                                <FontAwesomeIcon icon={faAngleDoubleRight} />
                            </PaginationButton.Text>
                        )}
                    </div>
                )}

                {pagination.totalCount > 0 && (
                    <p css={tw`text-center text-sm text-neutral-400 mt-4`}>
                        Showing {pagination.index + 1} -{' '}
                        {Math.min(pagination.index + pagination.resultCount, pagination.totalCount)} of{' '}
                        {pagination.totalCount.toLocaleString()} modpacks
                    </p>
                )}
            </FadeTransition>
        </div>
    );
};
