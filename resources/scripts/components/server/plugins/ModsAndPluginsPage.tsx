import { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import tw from 'twin.macro';
import PageContentBlock from '@/elements/PageContentBlock';
import Spinner from '@/elements/Spinner';
import ModsContainer from '@server/mods/ModsContainer';
import ContentTypeTabPanel from '@server/plugins/ContentTypeTabPanel';
import { ContentType, getPluginCapabilities, PluginCapabilityResponse, ProviderKey } from '@/api/routes/server/plugins';
import { useStoreState } from '@/state/hooks';
import { ServerContext } from '@/state/server';
import InstalledAddonsList from '@server/plugins/InstalledAddonsList';
import { getServerModsConfig, ServerModsConfig } from '@/api/routes/server/mods';
import { ExclamationIcon } from '@heroicons/react/outline';

type ContentTab = ContentType | 'installed';

const contentLabels: Record<ContentTab, string> = {
    mods: 'Mods',
    plugins: 'Plugins',
    installed: 'Installed',
};

const providerLabels: Record<ProviderKey, string> = {
    modrinth: 'Modrinth',
    spigot: 'Spigot',
};

const EmptyState = () => (
    <div css={tw`py-12 text-center`}>
        <p css={tw`text-lg text-neutral-200 mb-2`}>No marketplace integrations are enabled for this server's egg.</p>
        <p css={tw`text-sm text-neutral-400`}>
            Ask your admin to enable providers in Admin → Plugins → Access Control.
        </p>
    </div>
);

const ComingSoon = ({ label }: { label: string }) => (
    <div css={tw`py-12 text-center text-neutral-300`}>{label} (coming soon)</div>
);

const contentOrder: ContentTab[] = ['installed', 'mods', 'plugins'];

const resolveActive = <T,>(preferred: T | null, available: T[]): T | null =>
    preferred && available.includes(preferred) ? preferred : (available[0] ?? null);

type ProvidersByType = Record<ContentType, ProviderKey[]>;
const marketplaceTypes: ContentType[] = ['mods', 'plugins'];
const isMarketplaceType = (type: ContentTab | null): type is ContentType =>
    !!type && marketplaceTypes.includes(type as ContentType);

const ModsAndPluginsPage = () => {
    const modSettings = useStoreState(state => state.everest?.data?.mods);
    const { colors } = useStoreState(state => state.theme.data!);
    const serverUuid = ServerContext.useStoreState(state => state.server.data?.uuid);
    const uuidFallback = undefined as string | undefined;
    const uuid = serverUuid ?? uuidFallback;

    const [searchParams, setSearchParams] = useSearchParams();
    const [providerAccess, setProviderAccess] = useState<PluginCapabilityResponse | null>(null);
    const [loadingProviders, setLoadingProviders] = useState(true);
    const [detectedConfig, setDetectedConfig] = useState<ServerModsConfig | null>(null);
    const [compatDismissed, setCompatDismissed] = useState(false);

    const modsFeatureEnabled = modSettings?.enabled ?? false;

    useEffect(() => {
        if (!uuid) {
            setProviderAccess({ mods: [], plugins: [] });
            setLoadingProviders(false);
            return;
        }

        setLoadingProviders(true);
        getPluginCapabilities(uuid)
            .then(setProviderAccess)
            .finally(() => setLoadingProviders(false));
    }, [uuid]);

    useEffect(() => {
        if (!uuid) return;
        getServerModsConfig(uuid).then(setDetectedConfig).catch(() => {});
    }, [uuid]);

    const providersByType = useMemo<ProvidersByType>(() => {
        if (!modsFeatureEnabled || !providerAccess) {
            return { mods: [], plugins: [] };
        }

        return {
            mods: providerAccess.mods ?? [],
            plugins: providerAccess.plugins ?? [],
        };
    }, [modsFeatureEnabled, providerAccess]);

    const availableContentTypes = useMemo(() => {
        const result: ContentTab[] = ['installed'];
        if (providersByType.mods.length) result.push('mods');
        if (providersByType.plugins.length) result.push('plugins');
        return result;
    }, [providersByType]);

    const localStorageKey = 'marketplace:last';
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

    const initialType = (searchParams.get('type') as ContentTab | null) ?? lastMarketplaceState?.type ?? null;
    const initialProvider = (searchParams.get('provider') as ProviderKey | null) ?? lastMarketplaceState?.provider;
    const providerPools: Record<ContentType, ProviderKey[]> = {
        mods: providersByType.mods,
        plugins: providersByType.plugins,
    };
    const initialProviderPool = isMarketplaceType(initialType) ? providerPools[initialType] : providersByType.mods;

    const defaultType: ContentTab | null = 'installed';

    const [activeType, setActiveType] = useState<ContentTab | null>(
        () => resolveActive(initialType, availableContentTypes) ?? defaultType,
    );
    const [activeProvider, setActiveProvider] = useState<ProviderKey | null>(
        () => resolveActive(initialProvider, initialProviderPool) ?? null,
    );

    useEffect(() => {
        setCompatDismissed(false);
    }, [activeType]);

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

    const renderCompatWarning = () => {
        if (compatDismissed || !detectedConfig) return null;

        if (activeType === 'mods' && detectedConfig.detectedPlatform) {
            const platform = detectedConfig.detectedPlatform;
            const canSwitchToPlugins = availableContentTypes.includes('plugins');
            return (
                <div className={'mb-5 flex items-start gap-3 rounded-lg border border-amber-700 bg-amber-950 p-4'}>
                    <ExclamationIcon className={'h-5 w-5 flex-shrink-0 text-amber-400 mt-0.5'} />
                    <div className={'flex-1'}>
                        <p className={'text-sm font-medium text-amber-300'}>
                            Your server software (<span className={'capitalize'}>{platform}</span>) is a plugin-based platform that does not support mods.
                        </p>
                        <p className={'text-xs text-amber-500 mt-1'}>
                            Mods require a mod loader like Forge or Fabric. You may want to browse Plugins instead.
                        </p>
                        <div className={'flex gap-2 mt-3'}>
                            {canSwitchToPlugins && (
                                <button
                                    className={'rounded px-3 py-1.5 text-xs font-medium bg-neutral-700 hover:bg-neutral-600 text-neutral-100 transition-colors'}
                                    onClick={() => setActiveType('plugins')}
                                    type="button"
                                >
                                    Go to Plugins
                                </button>
                            )}
                            <button
                                className={'px-3 py-1.5 text-xs font-medium text-amber-400 hover:text-amber-300 transition-colors'}
                                onClick={() => setCompatDismissed(true)}
                                type="button"
                            >
                                Proceed Anyway
                            </button>
                        </div>
                    </div>
                </div>
            );
        }

        if (activeType === 'plugins' && detectedConfig.detectedLoader) {
            const loaderName = detectedConfig.detectedLoader.name;
            const canSwitchToMods = availableContentTypes.includes('mods');
            return (
                <div className={'mb-5 flex items-start gap-3 rounded-lg border border-amber-700 bg-amber-950 p-4'}>
                    <ExclamationIcon className={'h-5 w-5 flex-shrink-0 text-amber-400 mt-0.5'} />
                    <div className={'flex-1'}>
                        <p className={'text-sm font-medium text-amber-300'}>
                            Your server software (<span className={'font-semibold'}>{loaderName}</span>) is a mod loader that does not support plugins.
                        </p>
                        <p className={'text-xs text-amber-500 mt-1'}>
                            Plugins require a plugin-based platform like Spigot or Paper. You may want to browse Mods instead.
                        </p>
                        <div className={'flex gap-2 mt-3'}>
                            {canSwitchToMods && (
                                <button
                                    className={'rounded px-3 py-1.5 text-xs font-medium bg-neutral-700 hover:bg-neutral-600 text-neutral-100 transition-colors'}
                                    onClick={() => setActiveType('mods')}
                                    type="button"
                                >
                                    Go to Mods
                                </button>
                            )}
                            <button
                                className={'px-3 py-1.5 text-xs font-medium text-amber-400 hover:text-amber-300 transition-colors'}
                                onClick={() => setCompatDismissed(true)}
                                type="button"
                            >
                                Proceed Anyway
                            </button>
                        </div>
                    </div>
                </div>
            );
        }

        return null;
    };

    const renderContent = () => {
        if (loadingProviders && activeType !== 'installed') {
            return <Spinner size={'large'} centered />;
        }

        if (!modsFeatureEnabled && activeType !== 'installed') {
            return <EmptyState />;
        }

        if (!activeType) return null;

        if (activeType === 'installed') {
            return <InstalledAddonsList serverUuid={uuid} />;
        }

        if (activeType === 'mods') {
            if (!activeProvider) return null;
            const modsBlocked = !!detectedConfig?.detectedPlatform && !compatDismissed;
            return (
                <>
                    {renderCompatWarning()}
                    {!modsBlocked && renderProviderTabs(providersByType.mods, activeProvider)}
                    {!modsBlocked && activeProvider === 'modrinth' && <ModsContainer sourceOverride="modrinth" />}
                    {!modsBlocked && activeProvider === 'spigot' && <ModsContainer sourceOverride="spigot" />}
                </>
            );
        }

        if (activeType === 'plugins') {
            const pluginProvider = activeProvider ?? providersByType.plugins[0] ?? null;
            return (
                <>
                    {renderCompatWarning()}
                    {(!compatDismissed && detectedConfig?.detectedLoader) ? null : (
                        <>
                            {renderProviderTabs(providersByType.plugins, pluginProvider)}
                            {pluginProvider === 'spigot' ? (
                                <ModsContainer sourceOverride="spigot" contentType="plugins" />
                            ) : null}
                            {pluginProvider === 'modrinth' ? (
                                <ModsContainer sourceOverride="modrinth" contentType="plugins" />
                            ) : null}
                            {!['spigot', 'modrinth'].includes(pluginProvider ?? '') && <ComingSoon label={'Plugins'} />}
                        </>
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
                                    !active && tw`text-neutral-400 hover:text-neutral-200`,
                                ]}
                                style={
                                    active
                                        ? { color: colors.primary, borderBottom: `2px solid ${colors.primary}` }
                                        : undefined
                                }
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
