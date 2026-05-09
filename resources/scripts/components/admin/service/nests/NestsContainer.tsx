import { useContext, useEffect } from 'react';
import { NavLink } from 'react-router-dom';
import tw from 'twin.macro';

import type { Filters } from '@/api/routes/admin/nests/getNests';
import getNests, { Context as NestsContext } from '@/api/routes/admin/nests/getNests';
import AdminContentBlock from '@/elements/AdminContentBlock';
import AdminTable, { Pagination, Loading, NoItems, ContentWrapper, useTableHooks } from '@/elements/AdminTable';
import CopyOnClick from '@/elements/CopyOnClick';
import NewNestButton from '@admin/service/nests/NewNestButton';
import FlashMessageRender from '@/elements/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from '@/state/hooks';

const NestsContainer = () => {
    const { setPage, setFilters } = useContext(NestsContext);
    const { colors } = useStoreState(state => state.theme.data!);
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { data: nests, error, isValidating } = getNests(['eggs']);

    useEffect(() => {
        if (!error) {
            clearFlashes('nests');
            return;
        }

        clearAndAddHttpError({ key: 'nests', error });
    }, [error]);

    const length = nests?.items?.length || 0;

    const onSearch = (query: string): Promise<void> => {
        return new Promise(resolve => {
            if (query.length < 2) {
                setFilters(null);
            } else {
                setFilters({ name: query });
            }
            return resolve();
        });
    };

    return (
        <AdminContentBlock title={'Nests'}>
            <div css={tw`w-full flex flex-col gap-2 sm:flex-row sm:items-center mb-8`}>
                <div css={tw`flex flex-col flex-shrink`} style={{ minWidth: '0' }}>
                    <h2 css={tw`text-2xl text-neutral-50 font-header font-medium`}>Nests</h2>
                    <p css={tw`hidden md:block text-base text-neutral-400`}>Browse nests and jump directly into egg management.</p>
                </div>

                <div css={tw`flex ml-auto pl-4`}>
                    <NewNestButton />
                </div>
            </div>

            <FlashMessageRender byKey={'nests'} css={tw`mb-4`} />

            <AdminTable>
                <ContentWrapper onSearch={onSearch}>
                    <Pagination data={nests} onPageSelect={setPage}>
                        {nests === undefined || (error && isValidating) ? (
                            <Loading />
                        ) : length < 1 ? (
                            <NoItems />
                        ) : (
                            <div css={tw`grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5`}>
                                {nests.items.map(nest => (
                                    <div
                                        key={nest.id}
                                        css={tw`rounded-lg border border-neutral-700 bg-neutral-800/60 p-4 hover:border-neutral-500 transition`}
                                    >
                                        <div css={tw`flex items-start gap-3 mb-3`}>
                                            <div css={tw`text-xl`}>Egg</div>
                                            <div css={tw`flex-1 min-w-0`}>
                                                <NavLink
                                                    to={`/admin/nests/${nest.id}`}
                                                    style={{ color: colors.primary }}
                                                    className={'text-lg font-semibold hover:brightness-125'}
                                                >
                                                    {nest.name}
                                                </NavLink>
                                                <p css={tw`text-sm text-neutral-400 mt-1 line-clamp-2`}>
                                                    {nest.description || 'No description'}
                                                </p>
                                            </div>
                                        </div>

                                        <div css={tw`flex items-center gap-3 text-xs text-neutral-300 mb-3`}>
                                            <span css={tw`rounded bg-neutral-900 px-2 py-1`}>{nest.relations.eggs?.length || 0} Eggs</span>
                                            <CopyOnClick text={nest.id.toString()}>
                                                <span css={tw`rounded bg-neutral-900 px-2 py-1 cursor-pointer`}>ID #{nest.id}</span>
                                            </CopyOnClick>
                                        </div>

                                        <NavLink to={`/admin/nests/${nest.id}`}>
                                            <button
                                                type={'button'}
                                                css={tw`w-full rounded px-3 py-2 text-sm bg-neutral-900 border border-neutral-600 hover:border-neutral-400`}
                                            >
                                                View Nest
                                            </button>
                                        </NavLink>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Pagination>
                </ContentWrapper>
            </AdminTable>
        </AdminContentBlock>
    );
};

export default () => {
    const hooks = useTableHooks<Filters>();

    return (
        <NestsContext.Provider value={hooks}>
            <NestsContainer />
        </NestsContext.Provider>
    );
};
