import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import { useStoreState } from '@/state/hooks';
import PageContentBlock from '@/elements/PageContentBlock';
import ModSearch from './ModSearch';
import ModList from './ModList';
import ModDetails from './ModDetails';
import { type CurseForgeMod, type ModSearchParams, searchMods } from '@/api/routes/server/mods';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import Spinner from '@/elements/Spinner';

export default () => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const modsEnabled = ServerContext.useStoreState(state => state.server.data!.modsEnabled);
    const globalModsEnabled = useStoreState(state => state.everest.data?.mods?.enabled ?? false);
    const { addError } = useFlash();

    const [loading, setLoading] = useState(false);
    const [mods, setMods] = useState<CurseForgeMod[]>([]);
    const [selectedMod, setSelectedMod] = useState<CurseForgeMod | null>(null);
    const [pagination, setPagination] = useState({
        index: 0,
        pageSize: 20,
        resultCount: 0,
        totalCount: 0,
    });

    const [searchParams, setSearchParams] = useState<ModSearchParams>({
        searchFilter: '',
        sortField: '2',
        sortOrder: 'desc',
        gameVersion: undefined,
        modLoaderType: undefined,
        pageSize: 20,
        index: 0,
    });

    useEffect(() => {
        if (!modsEnabled) return;
        
        setLoading(true);
        searchMods(uuid, searchParams)
            .then(response => {
                setMods(response.data);
                setPagination(response.pagination);
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'mods', message: httpErrorToHuman(error) });
            })
            .finally(() => setLoading(false));
    }, [uuid, searchParams, modsEnabled]);

    const handleSearch = (params: ModSearchParams) => {
        setSearchParams({ ...params, index: 0 });
    };

    const handlePageChange = (newIndex: number) => {
        setSearchParams({ ...searchParams, index: newIndex });
    };

    if (!globalModsEnabled) {
        return (
            <PageContentBlock title={'Mods Browser'} header description={'Browse and install Minecraft mods.'}>
                <div css={tw`text-center py-16`}>
                    <p css={tw`text-neutral-300 text-lg mb-4`}>The Mods module is not enabled.</p>
                    <p css={tw`text-neutral-400 text-sm`}>
                        Contact your panel administrator to enable the Mods module in the admin area.
                    </p>
                </div>
            </PageContentBlock>
        );
    }

    if (!modsEnabled) {
        return (
            <PageContentBlock title={'Mods Browser'} header description={'Browse and install Minecraft mods.'}>
                <div css={tw`text-center py-16`}>
                    <p css={tw`text-neutral-300 text-lg mb-4`}>Mods are not enabled for this server.</p>
                    <p css={tw`text-neutral-400 text-sm mb-2`}>
                        An administrator needs to enable the mods feature for this specific server.
                    </p>
                    <p css={tw`text-neutral-400 text-xs`}>
                        This can be done in the admin panel under Servers → [Server Name] → Mods Toggle.
                    </p>
                </div>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock
            title={'Mods Browser'}
            header
            description={'Browse and install Minecraft mods from CurseForge.'}
            showFlashKey={'mods'}
        >
            <ModSearch onSearch={handleSearch} initialParams={searchParams} />

            {loading && !mods.length ? (
                <div css={tw`mt-8`}>
                    <Spinner size={'large'} centered />
                </div>
            ) : (
                <ModList
                    mods={mods}
                    loading={loading}
                    pagination={pagination}
                    onModClick={setSelectedMod}
                    onPageChange={handlePageChange}
                />
            )}

            {selectedMod && <ModDetails mod={selectedMod} onClose={() => setSelectedMod(null)} />}
        </PageContentBlock>
    );
};
