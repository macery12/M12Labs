import { useContext, useEffect } from 'react';
import { NavLink, useParams } from 'react-router-dom';
import tw from 'twin.macro';

import type { Filters } from '@/api/routes/admin/nests/getEggs';
import getEggs, { Context as EggsContext } from '@/api/routes/admin/nests/getEggs';
import AdminTable, { Pagination, Loading, NoItems, ContentWrapper, useTableHooks } from '@/elements/AdminTable';
import CopyOnClick from '@/elements/CopyOnClick';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from '@/state/hooks';

const EggsTable = () => {
    const params = useParams<'nestId' | 'id'>();

    const { setPage, setFilters } = useContext(EggsContext);
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { colors } = useStoreState(state => state.theme.data!);
    const { data: eggs, error, isValidating } = getEggs(Number(params.nestId), ['variables', 'servers']);

    useEffect(() => {
        if (!error) {
            clearFlashes('nests');
            return;
        }

        clearAndAddHttpError({ key: 'nests', error });
    }, [error]);

    const length = eggs?.items?.length || 0;

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
        <AdminTable>
            <ContentWrapper onSearch={onSearch}>
                <Pagination data={eggs} onPageSelect={setPage}>
                    {eggs === undefined || (error && isValidating) ? (
                        <Loading />
                    ) : length < 1 ? (
                        <NoItems />
                    ) : (
                        <div css={tw`grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-5`}>
                            {eggs.items.map(egg => (
                                <div
                                    key={egg.id}
                                    css={tw`rounded-lg border border-neutral-700 bg-neutral-800/70 p-4 hover:border-neutral-500 transition`}
                                >
                                    <div css={tw`flex items-start justify-between gap-2`}>
                                        <NavLink
                                            to={`/admin/nests/${params.nestId}/eggs/${egg.id}`}
                                            style={{ color: colors.primary }}
                                            className={'text-base font-semibold hover:brightness-125'}
                                        >
                                            {egg.name}
                                        </NavLink>
                                        <CopyOnClick text={egg.id.toString()}>
                                            <span css={tw`text-xs rounded bg-neutral-900 px-2 py-1 cursor-pointer`}>#{egg.id}</span>
                                        </CopyOnClick>
                                    </div>

                                    <p css={tw`text-sm text-neutral-400 mt-2 h-10 overflow-hidden`}>
                                        {egg.description || 'No description'}
                                    </p>

                                    <div css={tw`flex flex-wrap gap-2 mt-3 text-xs`}>
                                        <span css={tw`rounded bg-neutral-900 px-2 py-1`}>{Object.keys(egg.dockerImages).length} Images</span>
                                        <span css={tw`rounded bg-neutral-900 px-2 py-1`}>
                                            {egg.relations.variables?.length || 0} Variables
                                        </span>
                                        <span css={tw`rounded bg-neutral-900 px-2 py-1`}>
                                            {egg.relations.servers?.length || 0} Servers
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </Pagination>
            </ContentWrapper>
        </AdminTable>
    );
};

export default () => {
    const hooks = useTableHooks<Filters>();

    return (
        <EggsContext.Provider value={hooks}>
            <EggsTable />
        </EggsContext.Provider>
    );
};
