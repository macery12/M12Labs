import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import tw from 'twin.macro';
import PageContentBlock from '@/elements/PageContentBlock';
import Spinner from '@/elements/Spinner';
import ModsContainer from '@server/mods/ModsContainer';
import ModpacksContainer from '@server/modpacks/ModpacksContainer';
import ContentTypeTabPanel from '@server/plugins/ContentTypeTabPanel';
import {
    ContentType,
    getInstalledAddons,
    getPluginCapabilities,
    InstalledAddonResponse,
    deleteInstalledAddons,
    toggleInstalledAddons,
    rescanInstalledAddons,
    PluginCapabilityResponse,
    ProviderKey,
} from '@/api/routes/server/plugins';
import { useStoreState } from '@/state/hooks';
import { ServerContext } from '@/state/server';
import InstalledAddonsList from '@server/plugins/InstalledAddonsList';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';

type ContentTab = ContentType | 'installed';

const contentLabels: Record<ContentTab, string> = {
    mods: 'Mods',
    modpacks: 'Modpacks',
    plugins: 'Plugins',
    installed: 'Installed',
};

const providerLabels: Record<ProviderKey, string> = {
    modrinth: 'Modrinth',
    curseforge: 'CurseForge',
    spigot: 'Spigot',
};

const EmptyState = () => (
    <div css={tw`py-12 text-center`}>
        <p css={tw`text-lg text-neutral-200 mb-2`}>No marketplace integrations are enabled for this server’s egg.</p>
        <p css={tw`text-sm text-neutral-400`}>Ask your admin to enable providers in Admin → Plugins → Access Control.</p>
    </div>
);

const ComingSoon = ({ label }: { label: string }) => (
    <div css={tw`py-12 text-center text-neutral-300`}>{label} (coming soon)</div>
);

const contentOrder: ContentTab[] = ['installed', 'mods', 'modpacks', 'plugins'];
const MARKETPLACE_SAVE_DEBOUNCE_MS = 150;

const resolveActive = <T,>(preferred: T | null, available: T[]): T | null =>
    preferred && available.includes(preferred) ? preferred : available[0] ?? null;

type ProvidersByType = Record<ContentType, ProviderKey[]>;
const marketplaceTypes: ContentType[] = ['mods', 'modpacks', 'plugins'];
const isMarketplaceType = (type: ContentTab | null): type is ContentType =>
    !!type && marketplaceTypes.includes(type as ContentType);

const ModsAndPluginsPage = () => {
    const modSettings = useStoreState(state => state.everest?.data?.mods);
    const serverUuid = ServerContext.useStoreState(state => state.server.data?.uuid);
    const serverModsEnabled = ServerContext.useStoreState(state => state.server.data?.modsEnabled ?? false);
    const uuidFallback = useStoreState(state => state.server?.data?.uuid);
    const uuid = serverUuid ?? uuidFallback;
    const { addError } = useFlash();

    const [searchParams, setSearchParams] = useSearchParams();
    const [providerAccess, setProviderAccess] = useState<PluginCapabilityResponse | null>(null);
    const [loadingProviders, setLoadingProviders] = useState(true);
    const [installedAddons, setInstalledAddons] = useState<InstalledAddonResponse | null>(null);
    const [loadingInstalled, setLoadingInstalled] = useState(false);

    const modsFeatureEnabled = (modSettings?.enabled ?? false) && serverModsEnabled;
    const curseforgeConfigured = !!modSettings?.curseforge_api_key;

    useEffect(() => {
        if (!uuid) {
            setProviderAccess({ mods: [], modpacks: [], plugins: [] });
            setLoadingProviders(false);
            return;
        }

        setLoadingProviders(true);
        getPluginCapabilities(uuid)
            .then(setProviderAccess)
            .finally(() => setLoadingProviders(false));
    }, [uuid]);

    const providersByType = useMemo<ProvidersByType>(() => {
        if (!modsFeatureEnabled || !providerAccess) {
            return { mods: [], modpacks: [], plugins: [] };
        }

        const filterByConfig = (providers: ProviderKey[]) =>
            providers.filter(provider => {
                if (provider === 'curseforge' && !curseforgeConfigured) return false;
                return true;
            });

        return {
            mods: filterByConfig(providerAccess.mods ?? []),
            modpacks: filterByConfig(providerAccess.modpacks ?? []),
            plugins: filterByConfig(providerAccess.plugins ?? []),
        };
    }, [modsFeatureEnabled, providerAccess, curseforgeConfigured]);

    const availableContentTypes = useMemo(() => {
        const result: ContentTab[] = [];
        if (providersByType.mods.length) result.push('mods');
        if (providersByType.modpacks.length) result.push('modpacks');
        if (providersByType.plugins.length) result.push('plugins');
        result.push('installed');
        return result;
    }, [providersByType]);

    const localStorageKey = 'marketplace:last';
    const restoredParamsRef = useRef(false);

    useEffect(() => {
        if (restoredParamsRef.current) return;
        if (searchParams.toString()) {
            restoredParamsRef.current = true;
            return;
        }

        const saved = localStorage.getItem('marketplace:lastUrl');
        if (!saved) {
            restoredParamsRef.current = true;
            return;
        }

        try {
            const url = new URL(saved, window.location.origin);
            if (url.search) {
                setSearchParams(url.searchParams);
            }
        } catch {
            // Corrupted/invalid stored URL; safe to ignore and fall back to defaults.
        } finally {
            restoredParamsRef.current = true;
        }
    }, [searchParams, setSearchParams]);

    useEffect(() => {
        if (!searchParams.toString()) return;
        const handle = window.setTimeout(() => {
            localStorage.setItem('marketplace:lastUrl', `${window.location.pathname}?${searchParams.toString()}`);
        }, MARKETPLACE_SAVE_DEBOUNCE_MS);

        return () => window.clearTimeout(handle);
    }, [searchParams]);

    const lastMarketplaceState = useMemo(() => {
        const raw = localStorage.getItem(localStorageKey);
        if (!raw) return null;
        try {
            const parsed = JSON.parse(raw) as { type?: ContentType; provider?: ProviderKey };
            if (!isMarketplaceType(parsed.type ?? null)) return null;
            return parsed;
        } catch {
            return null;
        }
    }, []);

    const initialType = (searchParams.get('type') as ContentTab | null) ?? (lastMarketplaceState?.type ?? null);
    const initialProvider = (searchParams.get('provider') as ProviderKey | null) ?? lastMarketplaceState?.provider;
    const providerPools: Record<ContentType, ProviderKey[]> = {
        mods: providersByType.mods,
        modpacks: providersByType.modpacks,
        plugins: providersByType.plugins,
    };
    const initialProviderPool = isMarketplaceType(initialType) ? providerPools[initialType] : providersByType.mods;

    const defaultType = useMemo(() => {
        const primary = availableContentTypes.find(t => t !== 'installed');
        return primary ?? availableContentTypes[0] ?? null;
    }, [availableContentTypes]);

    const [activeType, setActiveType] = useState<ContentTab | null>(() =>
        resolveActive(initialType, availableContentTypes) ?? defaultType,
    );
    const [activeProvider, setActiveProvider] = useState<ProviderKey | null>(() =>
        resolveActive(initialProvider, initialProviderPool),
    );

    useEffect(() => {
        const type = (searchParams.get('type') as ContentTab | null) ?? null;
        const provider = (searchParams.get('provider') as ProviderKey | null) ?? null;
        const resolvedType = resolveActive(type, availableContentTypes) ?? defaultType;
        if (resolvedType && resolvedType !== activeType) {
            setActiveType(resolvedType);
        }

        if (resolvedType && resolvedType !== 'installed') {
            const pool = providerPools[resolvedType] ?? [];
            const resolvedProvider = resolveActive(provider, pool);
            if (resolvedProvider !== activeProvider) {
                setActiveProvider(resolvedProvider);
            }
        }
    }, [searchParams, availableContentTypes, defaultType, providerPools, activeType, activeProvider]);

    useEffect(() => {
        const resolvedType = resolveActive(activeType, availableContentTypes);
        const preferred = resolvedType ?? defaultType;
        if (preferred !== activeType) {
            setActiveType(preferred);
        }
    }, [activeType, availableContentTypes, defaultType]);

    useEffect(() => {
        if (!activeType) return;

        if (activeType === 'installed') {
            setActiveProvider(null);
            setSearchParams({ type: activeType });
            return;
        }

        const currentProviders = providersByType[activeType] ?? [];
        const resolvedProvider = resolveActive(activeProvider, currentProviders);
        if (resolvedProvider !== activeProvider) {
            setActiveProvider(resolvedProvider);
        }

        const params: Record<string, string> = { type: activeType };
        if (resolvedProvider) params.provider = resolvedProvider;
        setSearchParams(params);
        localStorage.setItem(localStorageKey, JSON.stringify({ type: activeType, provider: resolvedProvider }));
    }, [activeType, activeProvider, providersByType, setSearchParams]);

    const buildIconUrl = useCallback(
        (stableId?: string | null) => {
            if (!stableId || !uuid) return undefined;
            return `/api/client/servers/${uuid}/addons/icon/${stableId}`;
        },
        [uuid],
    );

    const decorateInstalled = useCallback(
        (data: InstalledAddonResponse | null) => {
            if (!data) return null;
            const mapIcon = (addon: InstalledAddon) => ({
                ...addon,
                iconUrl: addon.iconUrl ?? buildIconUrl(addon.stableId),
            });

            return {
                ...data,
                mods: (data.mods ?? []).map(mapIcon),
                plugins: (data.plugins ?? []).map(mapIcon),
            };
        },
        [buildIconUrl],
    );

    const fetchInstalled = useCallback(() => {
        if (!uuid) return;

        setLoadingInstalled(true);
        getInstalledAddons(uuid)
            .then(res => setInstalledAddons(decorateInstalled(res)))
            .catch(error => {
                console.error(error);
                setInstalledAddons(decorateInstalled({ mods: [], plugins: [] }));
                addError({ key: 'plugins', message: httpErrorToHuman(error) });
            })
            .finally(() => setLoadingInstalled(false));
    }, [uuid, addError, decorateInstalled]);

    useEffect(() => {
        if (activeType !== 'installed') return;

        fetchInstalled();
    }, [activeType, fetchInstalled]);

    const handleProviderChange = useCallback((provider: ProviderKey) => {
        setActiveProvider(provider);
    }, []);

    const handleDeleteInstalled = useCallback(
        async (paths: string[]) => {
            if (!uuid || !paths.length) return;

            try {
                await deleteInstalledAddons(uuid, paths);
                fetchInstalled();
            } catch (error) {
                console.error(error);
                addError({ key: 'plugins', message: httpErrorToHuman(error) });
            }
        },
        [uuid, fetchInstalled, addError],
    );

    const handleToggleInstalled = useCallback(
        async (paths: string[], disabled: boolean) => {
            if (!uuid || !paths.length) return;

            try {
                await toggleInstalledAddons(uuid, paths, disabled);
                fetchInstalled();
            } catch (error) {
                console.error(error);
                addError({ key: 'plugins', message: httpErrorToHuman(error) });
            }
        },
        [uuid, fetchInstalled, addError],
    );

    const handleRescanInstalled = useCallback(async () => {
        if (!uuid) return;
        try {
            await rescanInstalledAddons(uuid);
            setLoadingInstalled(true);
            setTimeout(() => fetchInstalled(), 1500);
        } catch (error) {
            console.error(error);
            addError({ key: 'plugins', message: httpErrorToHuman(error) });
        }
    }, [uuid, fetchInstalled, addError]);

    const renderProviderTabs = (providers: ProviderKey[], current: ProviderKey | null) =>
        providers.length > 1 ? (
            <div css={tw`mb-4`}>
                <p css={tw`text-xs uppercase text-neutral-400 mb-2 tracking-wide`}>Available Sources</p>
                <ContentTypeTabPanel
                    providers={providers}
                    activeProvider={current}
                    onChange={handleProviderChange}
                    providerLabels={providerLabels}
                />
            </div>
        ) : null;

    const renderContent = () => {
        if (loadingProviders && activeType !== 'installed') {
            return <Spinner size={'large'} centered />;
        }

        if (!modsFeatureEnabled && activeType !== 'installed') {
            return <EmptyState />;
        }

        if (!activeType) return null;

        if (activeType === 'installed') {
            return (
                <InstalledAddonsList
                    mods={installedAddons?.mods ?? []}
                    plugins={installedAddons?.plugins ?? []}
                    loading={loadingInstalled}
                    stats={installedAddons?.stats}
                    scanInProgress={installedAddons?.scanInProgress}
                    onRescan={handleRescanInstalled}
                    onDelete={handleDeleteInstalled}
                    onToggle={handleToggleInstalled}
                />
            );
        }

        if (activeType === 'mods') {
            if (!activeProvider) return null;
            return (
                <>
                    {renderProviderTabs(providersByType.mods, activeProvider)}
                    {activeProvider === 'modrinth' && <ModsContainer sourceOverride="modrinth" />}
                    {activeProvider === 'curseforge' && <ModsContainer sourceOverride="curseforge" />}
                    {activeProvider === 'spigot' && <ModsContainer sourceOverride="spigot" />}
                </>
            );
        }

        if (activeType === 'modpacks') {
            const modpackProvider = activeProvider ?? providersByType.modpacks[0];
            return (
                <>
                    {renderProviderTabs(providersByType.modpacks, modpackProvider)}
                    {modpackProvider === 'curseforge' ? (
                        <ModpacksContainer />
                    ) : (
                        <ComingSoon label={'Modpacks'} />
                    )}
                </>
            );
        }

        if (activeType === 'plugins') {
            const pluginProvider = activeProvider ?? providersByType.plugins[0];
            return (
                <>
                    {renderProviderTabs(providersByType.plugins, pluginProvider)}
                    {pluginProvider === 'spigot' ? (
                        <ModsContainer sourceOverride="spigot" />
                    ) : (
                        <ComingSoon label={'Plugins'} />
                    )}
                </>
            );
        }

        return null;
    };

    return (
        <PageContentBlock
            title={'Mods & Plugins'}
            description={'Browse and install server add-ons from supported providers.'}
            showFlashKey={'plugins'}
            header
        >
            <div css={tw`border-b border-neutral-700 mb-6 flex flex-wrap gap-2`}>
                {contentOrder
                    .filter(type => availableContentTypes.includes(type))
                    .map(type => {
                        const active = type === activeType;
                        return (
                            <button
                                key={type}
                                css={[
                                    tw`px-4 py-2 font-medium transition-colors rounded-t`,
                                    active
                                        ? tw`text-blue-400 border-b-2 border-blue-400`
                                        : tw`text-neutral-400 hover:text-neutral-200`,
                                ]}
                                onClick={() => setActiveType(type)}
                                type="button"
                            >
                                {contentLabels[type]}
                            </button>
                        );
                    })}
            </div>

            {renderContent()}
        </PageContentBlock>
    );
};

export default ModsAndPluginsPage;
