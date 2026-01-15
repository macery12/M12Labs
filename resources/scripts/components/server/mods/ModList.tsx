import tw from 'twin.macro';
import styled from 'styled-components';
import { type CurseForgeMod } from '@/api/routes/server/mods';
import Spinner from '@/elements/Spinner';
import { Button } from '@/elements/button';
import FadeTransition from '@/elements/transitions/FadeTransition';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faAngleDoubleLeft, faAngleDoubleRight } from '@fortawesome/free-solid-svg-icons';

interface Props {
    mods: CurseForgeMod[];
    loading: boolean;
    pagination: {
        index: number;
        pageSize: number;
        resultCount: number;
        totalCount: number;
    };
    onModClick: (mod: CurseForgeMod) => void;
    onPageChange: (index: number) => void;
}

const ModCard = styled.div`
    ${tw`bg-neutral-800 rounded-lg overflow-hidden cursor-pointer transition-all duration-200 hover:bg-neutral-700 border border-neutral-700`}
    
    &:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        border-color: rgba(139, 92, 246, 0.5);
    }
`;

const ModImage = styled.img`
    ${tw`w-full h-20 object-cover`}
`;

const ModContent = styled.div`
    ${tw`p-2.5`}
`;

const ModName = styled.h3`
    ${tw`text-xs font-semibold text-neutral-100 mb-1 truncate`}
`;

const ModDescription = styled.p`
    ${tw`text-xs text-neutral-400 mb-1.5`}
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.3;
    height: 2.6em;
`;

const ModMeta = styled.div`
    ${tw`flex justify-between items-center text-xs text-neutral-500`}
`;

const PaginationButton = styled(Button)`
    ${tw`p-0 w-10 h-10`}

    &:not(:last-of-type) {
        ${tw`mr-2`};
    }
`;

export default ({ mods, loading, pagination, onModClick, onPageChange }: Props) => {
    if (!mods.length && !loading) {
        return (
            <div css={tw`text-center py-16`}>
                <p css={tw`text-neutral-400 text-lg`}>No mods found matching your search criteria.</p>
            </div>
        );
    }

    const totalPages = Math.ceil(pagination.totalCount / pagination.pageSize);
    const currentPage = pagination.index + 1;
    const isFirstPage = currentPage === 1;
    const isLastPage = currentPage >= totalPages;

    const pages = [];
    const start = Math.max(currentPage - 2, 1);
    const end = Math.min(totalPages, currentPage + 2);

    for (let i = start; i <= end; i++) {
        pages.push(i);
    }

    return (
        <div css={tw`mt-6`}>
            <FadeTransition duration="duration-150" show={!loading}>
                <div css={tw`grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-4`}>
                    {mods.map(mod => (
                        <ModCard key={mod.id} onClick={() => onModClick(mod)}>
                            <ModImage
                                src={mod.logo?.thumbnailUrl || '/assets/images/placeholder-mod.png'}
                                alt={mod.name}
                                onError={(e) => {
                                    e.currentTarget.src = '/assets/images/placeholder-mod.png';
                                }}
                            />
                            <ModContent>
                                <ModName>{mod.name}</ModName>
                                <ModDescription>{mod.summary}</ModDescription>
                                <ModMeta>
                                    <span>{mod.authors[0]?.name || 'Unknown'}</span>
                                    <span>{(mod.downloadCount / 1000000).toFixed(1)}M</span>
                                </ModMeta>
                            </ModContent>
                        </ModCard>
                    ))}
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
                                onClick={() => onPageChange(0)}
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
                                onClick={() => onPageChange(i - 1)}
                                disabled={i === currentPage}
                            >
                                {i}
                            </PaginationButton.Text>
                        ))}
                        {!isLastPage && pages[pages.length - 1] < totalPages && (
                            <PaginationButton.Text
                                size={Button.Sizes.Small}
                                onClick={() => onPageChange(totalPages - 1)}
                                className={'mx-1'}
                            >
                                <FontAwesomeIcon icon={faAngleDoubleRight} />
                            </PaginationButton.Text>
                        )}
                    </div>
                )}

                {pagination.totalCount > 0 && (
                    <p css={tw`text-center text-sm text-neutral-400 mt-4`}>
                        Showing {pagination.index * pagination.pageSize + 1} -{' '}
                        {Math.min((pagination.index + 1) * pagination.pageSize, pagination.totalCount)} of{' '}
                        {pagination.totalCount.toLocaleString()} mods
                    </p>
                )}
            </FadeTransition>
        </div>
    );
};
