import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import tw from 'twin.macro';
import PageContentBlock from '@/elements/PageContentBlock';
import Spinner from '@/elements/Spinner';
import ModsContainer from '@server/mods/ModsContainer';
import ModpacksContainer from '@server/modpacks/ModpacksContainer';
import ContentTypeTabPanel from '@server/plugins/ContentTypeTabPanel';
import { ContentType, getPluginProviders, PluginProviderAccessResponse, ProviderKey } from '@/api/routes/server/plugins';
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
    spiget: 'Spiget',
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

const providerOrder: ProviderKey[] = ['modrinth', 'curseforge', 'spiget'];
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
    const [providerAccess, setProviderAccess] = useState<PluginProviderAccessResponse | null>(null);
    const [loadingProviders, setLoadingProviders] = useState(true);

    const modsFeatureEnabled = (modSettings?.enabled ?? false) && serverModsEnabled;
    const curseforgeConfigured = !!modSettings?.curseforge_api_key;

    useEffect(() => {
        if (!uuid) {
            setProviderAccess({ mods: [], modpacks: [], plugins: [], installed: true });
            setLoadingProviders(false);
            return;
        }

        setLoadingProviders(true);
        getPluginProviders(uuid)
            .then(setProviderAccess)
            .finally(() => setLoadingProviders(false));
    }, [uuid]);

    const providersByType = useMemo<ProvidersByType>(() => {
        if (!modsFeatureEnabled || !providerAccess) {
            return { mods: [] as ProviderKey[], modpacks: [] as ProviderKey[], plugins: [] as ProviderKey[] };
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

    const hasProviderTabs =
        providersByType.mods.length > 0 || providersByType.modpacks.length > 0 || providersByType.plugins.length > 0;

    const availableContentTypes = useMemo(() => {
        if (!hasProviderTabs) {
            return [] as ContentTab[];
        }

        return contentOrder.filter(type => {
            if (type === 'installed') {
                return providerAccess?.installed ?? true;
            }
            return (providersByType as ProvidersByType)[type]?.length > 0;
        });
    }, [providerAccess, providersByType, hasProviderTabs]);

    const initialType = (searchParams.get('type') as ContentTab | null) ?? null;
    const initialProvider = (searchParams.get('provider') as ProviderKey | null) ?? null;

    const [activeType, setActiveType] = useState<ContentTab | null>(() =>
        resolveActive(initialType, availableContentTypes),
    );
    const [activeProvider, setActiveProvider] = useState<ProviderKey | null>(() =>
        resolveActive(initialProvider, providerOrder.filter(p => providersByType.mods.includes(p))),
    );

    useEffect(() => {
        const resolvedType = resolveActive(activeType, availableContentTypes);
        if (resolvedType !== activeType) {
            setActiveType(resolvedType);
        }

        const currentProviders =
            resolvedType && resolvedType !== 'installed'
                ? (providersByType as any)[resolvedType] ?? []
                : (providersByType as any).mods ?? [];
        const resolvedProvider = resolveActive(activeProvider, currentProviders);
        if (resolvedProvider !== activeProvider) {
            setActiveProvider(resolvedProvider);
        }

        if (resolvedType) {
            const params: Record<string, string> = { type: resolvedType };
            if (resolvedProvider) params.provider = resolvedProvider;
            setSearchParams(params);
        }
    }, [availableContentTypes, providersByType, activeType, activeProvider, setSearchParams]);

    const renderContent = () => {
        if (loadingProviders) {
            return <Spinner size={'large'} centered />;
        }

        if (!modsFeatureEnabled || !availableContentTypes.length) {
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
                    <ContentTypeTabPanel
                        providers={providersByType.mods}
                        activeProvider={activeProvider}
                        onChange={setActiveProvider}
                        providerLabels={providerLabels}
                    />
                    {activeProvider === 'modrinth' && <ModsContainer sourceOverride="modrinth" />}
                    {activeProvider === 'curseforge' && <ModsContainer sourceOverride="curseforge" />}
                    {activeProvider === 'spiget' && <ModsContainer sourceOverride="spiget" />}
                </>
            );
        }

        if (activeType === 'modpacks') {
            const modpackProvider = resolveActive(activeProvider, providersByType.modpacks);
            return (
                <>
                    <ContentTypeTabPanel
                        providers={providersByType.modpacks}
                        activeProvider={modpackProvider}
                        onChange={setActiveProvider}
                        providerLabels={providerLabels}
                    />
                    {modpackProvider === 'curseforge' ? (
                        <ModpacksContainer />
                    ) : (
                        <ComingSoon label={'Modpacks'} />
                    )}
                </>
            );
        }

        if (activeType === 'plugins') {
            const pluginProvider = resolveActive(activeProvider, providersByType.plugins);
            return (
                <>
                    <ContentTypeTabPanel
                        providers={providersByType.plugins}
                        activeProvider={pluginProvider}
                        onChange={setActiveProvider}
                        providerLabels={providerLabels}
                    />
                    {pluginProvider === 'spiget' ? (
                        <ModsContainer sourceOverride="spiget" />
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
