import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import tw from 'twin.macro';
import PageContentBlock from '@/elements/PageContentBlock';
import Spinner from '@/elements/Spinner';
import ModsContainer from '@server/mods/ModsContainer';
import ModpacksContainer from '@server/modpacks/ModpacksContainer';
import ContentTypeTabPanel from '@server/plugins/ContentTypeTabPanel';
import { ContentType, getPluginCapabilities, PluginCapabilityResponse, ProviderKey } from '@/api/routes/server/plugins';
import { useStoreState } from '@/state/hooks';
import { ServerContext } from '@/state/server';

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

const contentOrder: ContentTab[] = ['mods', 'modpacks', 'plugins', 'installed'];

const resolveActive = <T,>(preferred: T | null, available: T[]): T | null =>
    preferred && available.includes(preferred) ? preferred : available[0] ?? null;

type ProvidersByType = Record<ContentType, ProviderKey[]>;

const ModsAndPluginsPage = () => {
    const modSettings = useStoreState(state => state.everest?.data?.mods);
    const serverUuid = ServerContext.useStoreState(state => state.server.data?.uuid);
    const serverModsEnabled = ServerContext.useStoreState(state => state.server.data?.modsEnabled ?? false);
    const uuidFallback = useStoreState(state => state.server?.data?.uuid);
    const uuid = serverUuid ?? uuidFallback;

    const [searchParams, setSearchParams] = useSearchParams();
    const [providerAccess, setProviderAccess] = useState<PluginCapabilityResponse | null>(null);
    const [loadingProviders, setLoadingProviders] = useState(true);

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

    const initialType = (searchParams.get('type') as ContentTab | null) ?? null;
    const initialProvider = (searchParams.get('provider') as ProviderKey | null) ?? null;
    const providerPools: Record<ContentType, ProviderKey[]> = {
        mods: providersByType.mods,
        modpacks: providersByType.modpacks,
        plugins: providersByType.plugins,
    };
    const initialProviderPool =
        initialType && initialType !== 'installed' ? providerPools[initialType] : providersByType.mods;

    const [activeType, setActiveType] = useState<ContentTab | null>(() =>
        resolveActive(initialType, availableContentTypes),
    );
    const [activeProvider, setActiveProvider] = useState<ProviderKey | null>(() =>
        resolveActive(initialProvider, initialProviderPool),
    );

    useEffect(() => {
        const resolvedType = resolveActive(activeType, availableContentTypes);
        if (resolvedType !== activeType) {
            setActiveType(resolvedType);
        }
    }, [activeType, availableContentTypes]);

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
    }, [activeType, activeProvider, providersByType, setSearchParams]);

    const handleProviderChange = useCallback((provider: ProviderKey) => {
        setActiveProvider(provider);
    }, []);

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
        if (loadingProviders) {
            return <Spinner size={'large'} centered />;
        }

        if (!modsFeatureEnabled && activeType !== 'installed') {
            return <EmptyState />;
        }

        if (!activeType) return null;

        if (activeType === 'installed') {
            return (
                <div css={tw`space-y-4`}>
                    <ComingSoon label={'Installed Mods'} />
                    <ComingSoon label={'Installed Plugins'} />
                    <ComingSoon label={'Installed Modpacks'} />
                </div>
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
