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

type ModSource = 'modrinth' | 'curseforge' | 'spigot';

interface Props {
    sourceOverride?: ModSource;
    contentType?: 'mods' | 'plugins';
}

export default ({ sourceOverride, contentType = 'mods' }: Props) => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const globalModsEnabled = useStoreState(state => state.everest.data?.mods?.enabled ?? false);
    const curseforgeConfigured = useStoreState(state => state.everest.data?.mods?.curseforge_api_key ?? false);
    const spigotEnabled = useStoreState(state => state.everest.data?.mods?.spiget_enabled ?? false);
    const defaultSource = useStoreState(state => state.everest.data?.mods?.default_source ?? 'modrinth');
    const normalizedDefaultSource = defaultSource === 'spiget' ? 'spigot' : defaultSource;
    const validSources: ModSource[] = ['modrinth', 'curseforge', 'spigot'];
    const resolvedDefaultSource = (validSources.includes(normalizedDefaultSource as ModSource)
        ? (normalizedDefaultSource as ModSource)
        : 'modrinth') as ModSource;
    const { addError } = useFlash();
    const contentLabel = contentType === 'plugins' ? 'Plugins' : 'Mods';
    const contentLabelLower = contentType === 'plugins' ? 'plugins' : 'mods';

    const [loading, setLoading] = useState(false);
    const [mods, setMods] = useState<CurseForgeMod[]>([]);
    const [selectedMod, setSelectedMod] = useState<CurseForgeMod | null>(null);
    const [activeSource, setActiveSource] = useState<ModSource>(sourceOverride ?? resolvedDefaultSource);
    const [pagination, setPagination] = useState({
        index: 0,
        pageSize: 20,
        resultCount: 0,
        totalCount: 0,
    });
    const [filtersMeta, setFiltersMeta] = useState<any>(null);

    const [searchParams, setSearchParams] = useState<ModSearchParams>({
        searchFilter: '',
        sortField: (sourceOverride ?? activeSource) === 'spigot' ? 'downloads' : '2',
        sortOrder: 'desc',
        gameVersion: undefined,
        modLoaderType: undefined,
        pageSize: 20,
        index: 0,
        source: sourceOverride ?? activeSource,
        minRating: undefined,
        platform: undefined,
        categoryId: undefined,
        resource: contentType,
    });

    useEffect(() => {
        if (!sourceOverride) return;
        setActiveSource(sourceOverride);
        setSelectedMod(null);
        setSearchParams({
            searchFilter: '',
            sortField: sourceOverride === 'spigot' ? 'downloads' : '2',
            sortOrder: 'desc',
            gameVersion: undefined,
            modLoaderType: undefined,
            pageSize: 20,
            index: 0,
            source: sourceOverride,
            minRating: undefined,
            platform: undefined,
            categoryId: undefined,
            resource: contentType,
        });
        setFiltersMeta(null);
    }, [sourceOverride, contentType]);

    useEffect(() => {
        if (!globalModsEnabled) return;

        setLoading(true);
        searchMods(uuid, searchParams)
            .then(response => {
                setMods(response.data);
                setPagination(response.pagination);
                if ((response as any).filters) {
                    setFiltersMeta((response as any).filters);
                }
            })
            .catch(error => {
                console.error(error);
                addError({ key: 'mods', message: httpErrorToHuman(error) });
            })
            .finally(() => setLoading(false));
    }, [uuid, searchParams, globalModsEnabled]);

    const handleSearch = (params: ModSearchParams) => {
        setSelectedMod(null); // Close modal when searching
        setSearchParams({ ...params, index: 0, source: activeSource, resource: contentType });
    };

    const handlePageChange = (newIndex: number) => {
        setSelectedMod(null); // Close modal when changing pages
        setSearchParams({ ...searchParams, index: newIndex });
    };

    const handleModClick = (mod: CurseForgeMod) => {
        setSelectedMod(mod);
    };

    const handleSourceChange = (source: ModSource) => {
        if (sourceOverride) return;
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
            sortField: source === 'spigot' ? 'downloads' : '2',
            minRating: undefined,
            platform: undefined,
            categoryId: undefined,
            resource: contentType,
        });
        setFiltersMeta(null);
    };

    if (!globalModsEnabled) {
        return (
            <PageContentBlock
                title={`${contentLabel} Browser`}
                header
                description={`Browse and install Minecraft ${contentLabelLower}.`}
            >
                <div css={tw`text-center py-16`}>
                    <p css={tw`text-neutral-300 text-lg mb-4`}>The Mods module is not enabled.</p>
                    <p css={tw`text-neutral-400 text-sm`}>
                        Contact your panel administrator to enable the Mods module in the admin area.
                    </p>
                </div>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock
                title={`${contentLabel} Browser`}
                header
                description={`Browse and install Minecraft ${contentLabelLower} from ${
                    activeSource === 'modrinth'
                        ? 'Modrinth'
                        : activeSource === 'curseforge'
                        ? 'CurseForge'
                        : 'Spigot'
                }.`}
                showFlashKey={'mods'}
            >
                {/* Source Tabs */}
                {!sourceOverride && (
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
                        {spigotEnabled && (
                            <button
                                css={[
                                    tw`px-4 py-2 font-medium transition-colors`,
                                    activeSource === 'spigot'
                                        ? tw`text-blue-400 border-b-2 border-blue-400`
                                        : tw`text-neutral-400 hover:text-neutral-300`,
                                ]}
                                onClick={() => handleSourceChange('spigot')}
                            >
                                Spigot
                            </button>
                        )}
                    </div>
                )}

            <ModSearch
                onSearch={handleSearch}
                initialParams={searchParams}
                source={activeSource}
                contentType={contentType}
                filtersMeta={filtersMeta}
            />

            {loading && !mods.length ? (
                <div css={tw`mt-8`}>
                    <Spinner size={'large'} centered />
                </div>
            ) : (
                <ModList
                    mods={mods}
                    loading={loading}
                    contentType={contentType}
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
                    gameVersion={searchParams.gameVersion}
                    modLoaderType={searchParams.modLoaderType}
                    contentType={contentType}
                />
            )}
        </PageContentBlock>
    );
};
