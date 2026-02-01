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
    const curseforgeConfigured = useStoreState(state => state.everest.data?.mods?.curseforge_api_key ?? false);
    const defaultSource = useStoreState(state => state.everest.data?.mods?.default_source ?? 'modrinth');
    const { addError } = useFlash();

    const [loading, setLoading] = useState(false);
    const [mods, setMods] = useState<CurseForgeMod[]>([]);
    const [selectedMod, setSelectedMod] = useState<CurseForgeMod | null>(null);
    const [activeSource, setActiveSource] = useState<string>(defaultSource);
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
        source: activeSource,
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
        setSelectedMod(null); // Close modal when searching
        setSearchParams({ ...params, index: 0, source: activeSource });
    };

    const handlePageChange = (newIndex: number) => {
        setSelectedMod(null); // Close modal when changing pages
        setSearchParams({ ...searchParams, index: newIndex });
    };

    const handleModClick = (mod: CurseForgeMod) => {
        setSelectedMod(mod);
    };

    const handleSourceChange = (source: string) => {
        setActiveSource(source);
        setSelectedMod(null);
        setSearchParams({
            ...searchParams,
            source,
            index: 0,
            // Reset filters when switching sources
            searchFilter: '',
            gameVersion: undefined,
            modLoaderType: undefined,
        });
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
            description={`Browse and install Minecraft mods from ${
                activeSource === 'modrinth' ? 'Modrinth' : 'CurseForge'
            }.`}
            showFlashKey={'mods'}
        >
            {/* Source Tabs */}
            <div css={tw`flex gap-2 mb-6 border-b border-neutral-700`}>
                <button
                    css={[
                        tw`px-4 py-2 font-medium transition-colors`,
                        activeSource === 'modrinth'
                            ? tw`text-blue-400 border-b-2 border-blue-400`
                            : tw`text-neutral-400 hover:text-neutral-300`,
                    ]}
                    onClick={() => handleSourceChange('modrinth')}
                >
                    Modrinth
                </button>
                {curseforgeConfigured && (
                    <button
                        css={[
                            tw`px-4 py-2 font-medium transition-colors`,
                            activeSource === 'curseforge'
                                ? tw`text-blue-400 border-b-2 border-blue-400`
                                : tw`text-neutral-400 hover:text-neutral-300`,
                        ]}
                        onClick={() => handleSourceChange('curseforge')}
                    >
                        CurseForge
                    </button>
                )}
            </div>

            <ModSearch onSearch={handleSearch} initialParams={searchParams} source={activeSource} />

            {loading && !mods.length ? (
                <div css={tw`mt-8`}>
                    <Spinner size={'large'} centered />
                </div>
            ) : (
                <ModList
                    mods={mods}
                    loading={loading}
                    pagination={pagination}
                    onModClick={handleModClick}
                    onPageChange={handlePageChange}
                />
            )}

            {selectedMod && (
                <ModDetails
                    key={selectedMod.id}
                    mod={selectedMod}
                    onClose={() => setSelectedMod(null)}
                    source={activeSource}
                />
            )}
        </PageContentBlock>
    );
};
