import { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import { useStoreState } from '@/state/hooks';
import FlashMessageRender from '@/elements/FlashMessageRender';
import ModSearch from './ModSearch';
import ModList from './ModList';
import ModDetails from './ModDetails';
import { type CurseForgeMod, type ModSearchParams, type ServerModsConfig, searchMods, getServerModsConfig } from '@/api/routes/server/mods';
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
    const { colors } = useStoreState(state => state.theme.data!);
    const globalModsEnabled = useStoreState(state => state.everest.data?.mods?.enabled ?? false);
    const curseforgeConfigured = useStoreState(state => state.everest.data?.mods?.curseforge_api_key ?? false);
    const spigotEnabled = useStoreState(state => state.everest.data?.mods?.spiget_enabled ?? false);
    const defaultSource = useStoreState(state => state.everest.data?.mods?.default_source ?? 'modrinth');
    const normalizedDefaultSource = defaultSource === 'spiget' ? 'spigot' : defaultSource;
    const validSources: ModSource[] = ['modrinth', 'curseforge', 'spigot'];
    const resolvedDefaultSource = (
        validSources.includes(normalizedDefaultSource as ModSource)
            ? (normalizedDefaultSource as ModSource)
            : 'modrinth'
    ) as ModSource;
    const { addError } = useFlash();

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
    const [detectedConfig, setDetectedConfig] = useState<ServerModsConfig | null>(null);
    const [configLoaded, setConfigLoaded] = useState(false);

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
        getServerModsConfig(uuid)
            .then(config => {
                setDetectedConfig(config);
                setSearchParams(prev => ({
                    ...prev,
                    gameVersion: config.detectedVersion ?? undefined,
                    modLoaderType: config.detectedLoader?.id ?? undefined,
                    platform: (contentType === 'plugins' && config.detectedPlatform) ? config.detectedPlatform : undefined,
                }));
            })
            .catch(() => {
                // If the endpoint fails, just proceed without pre-population
            })
            .finally(() => setConfigLoaded(true));
    }, [uuid]);

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
        if (!globalModsEnabled || !configLoaded) return;

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
    }, [uuid, searchParams, globalModsEnabled, configLoaded]);

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
            searchFilter: '',
            gameVersion: source !== 'spigot' ? (detectedConfig?.detectedVersion ?? undefined) : undefined,
            modLoaderType: source !== 'spigot' ? (detectedConfig?.detectedLoader?.id ?? undefined) : undefined,
            platform: (source !== 'spigot' && contentType === 'plugins')
                ? (detectedConfig?.detectedPlatform ?? undefined)
                : undefined,
            sortField: source === 'spigot' ? 'downloads' : '2',
            minRating: undefined,
            categoryId: undefined,
            resource: contentType,
        });
        setFiltersMeta(null);
    };

    const handleShowAll = () => {
        setSearchParams(prev => ({ ...prev, gameVersion: undefined, modLoaderType: undefined, platform: undefined, index: 0 }));
        setSelectedMod(null);
    };

    if (!globalModsEnabled) {
        return (
            <div css={tw`text-center py-16`}>
                <p css={tw`text-neutral-300 text-lg mb-4`}>The Mods module is not enabled.</p>
                <p css={tw`text-neutral-400 text-sm`}>
                    Contact your panel administrator to enable the Mods module in the admin area.
                </p>
            </div>
        );
    }

    return (
        <>
            <FlashMessageRender byKey={'mods'} css={tw`mb-4`} />
            {/* Source Tabs */}
            {!sourceOverride && (
                <div css={tw`flex gap-2 mb-6 border-b border-neutral-700`}>
                    <button
                        css={[
                            tw`px-4 py-2 font-medium transition-colors`,
                            activeSource !== 'modrinth' && tw`text-neutral-400 hover:text-neutral-300`,
                        ]}
                        style={
                            activeSource === 'modrinth'
                                ? { color: colors.primary, borderBottom: `2px solid ${colors.primary}` }
                                : undefined
                        }
                        onClick={() => handleSourceChange('modrinth')}
                    >
                        Modrinth
                    </button>
                    {curseforgeConfigured && contentType !== 'plugins' && (
                        <button
                            css={[
                                tw`px-4 py-2 font-medium transition-colors`,
                                activeSource !== 'curseforge' && tw`text-neutral-400 hover:text-neutral-300`,
                            ]}
                            style={
                                activeSource === 'curseforge'
                                    ? { color: colors.primary, borderBottom: `2px solid ${colors.primary}` }
                                    : undefined
                            }
                            onClick={() => handleSourceChange('curseforge')}
                        >
                            CurseForge
                        </button>
                    )}
                    {spigotEnabled && (
                        <button
                            css={[
                                tw`px-4 py-2 font-medium transition-colors`,
                                activeSource !== 'spigot' && tw`text-neutral-400 hover:text-neutral-300`,
                            ]}
                            style={
                                activeSource === 'spigot'
                                    ? { color: colors.primary, borderBottom: `2px solid ${colors.primary}` }
                                    : undefined
                            }
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
                detectedConfig={detectedConfig}
                onShowAll={handleShowAll}
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
                    gameVersion={searchParams.gameVersion}
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
                    platform={searchParams.platform}
                />
            )}
        </>
    );
};
