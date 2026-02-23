import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import tw from 'twin.macro';
import PageContentBlock from '@/elements/PageContentBlock';
import { useStoreState } from '@/state/hooks';
import ModsContainer from '@server/mods/ModsContainer';
import ModpacksContainer from '@server/modpacks/ModpacksContainer';

type Provider = 'modrinth' | 'curseforge' | 'spiget';
type Resource = 'mods' | 'modpacks' | 'plugins';

interface ProviderState {
    available: boolean;
    reason?: string;
}

const ComingSoon = ({ message }: { message: string }) => (
    <div css={tw`py-12 text-center text-neutral-300`}>{message}</div>
);

const NotConfigured = ({ label, reason }: { label: string; reason?: string }) => (
    <div css={tw`py-12 text-center`}>
        <p css={tw`text-lg text-neutral-200 mb-2`}>{label} is not configured for this server.</p>
        {reason && <p css={tw`text-sm text-neutral-400`}>{reason}</p>}
    </div>
);

const providerOrder: Provider[] = ['modrinth', 'curseforge', 'spiget'];
const providerLabels: Record<Provider, string> = {
    modrinth: 'Modrinth',
    curseforge: 'CurseForge',
    spiget: 'Spiget',
};

export default function PluginsContainer() {
    const globalModsEnabled = useStoreState(state => state.everest.data?.mods?.enabled ?? false);
    const curseforgeConfigured = !!useStoreState(state => state.everest.data?.mods?.curseforge_api_key);
    // Spiget configuration is not yet implemented; treat as disabled unless future settings populate it.
    const spigetEnabled = !!useStoreState(state => state.everest.data?.plugins?.spiget?.enabled);

    const providers: Record<Provider, ProviderState> = useMemo(
        () => ({
            modrinth: {
                available: globalModsEnabled,
                reason: globalModsEnabled ? undefined : 'Mods module is disabled by the administrator.',
            },
            curseforge: {
                available: globalModsEnabled && curseforgeConfigured,
                reason: !globalModsEnabled
                    ? 'Mods module is disabled by the administrator.'
                    : 'CurseForge API key is not configured.',
            },
            spiget: {
                available: spigetEnabled,
                reason: spigetEnabled ? undefined : 'Spiget integration is not configured yet.',
            },
        }),
        [globalModsEnabled, curseforgeConfigured, spigetEnabled],
    );

    const [searchParams, setSearchParams] = useSearchParams();

    const hasAvailableProvider = providerOrder.some(p => providers[p].available);

    const pickFirstAvailableProvider = (): Provider =>
        providerOrder.find(p => providers[p].available) ?? providerOrder[0];

    const initialProviderParam = searchParams.get('provider') as Provider | null;
    const initialResourceParam = searchParams.get('resource') as Resource | null;

    const [activeProvider, setActiveProvider] = useState<Provider>(
        initialProviderParam && providers[initialProviderParam]
            ? initialProviderParam
            : pickFirstAvailableProvider(),
    );

    const resourceOptions: Record<Provider, Array<{ id: Resource; label: string; enabled: boolean; comingSoon?: boolean }>> = {
        modrinth: [
            { id: 'mods', label: 'Mods', enabled: true },
            { id: 'modpacks', label: 'Modpacks', enabled: false, comingSoon: true },
        ],
        curseforge: [
            { id: 'mods', label: 'Mods', enabled: true },
            { id: 'modpacks', label: 'Modpacks', enabled: true },
        ],
        spiget: [{ id: 'plugins', label: 'Plugins', enabled: providers.spiget.available }],
    };

    const pickFirstAvailableResource = (provider: Provider): Resource => {
        const options = resourceOptions[provider];
        const firstEnabled = options.find(r => r.enabled)?.id;
        return firstEnabled ?? options[0]?.id ?? 'mods';
    };

    const [activeResource, setActiveResource] = useState<Resource>(
        initialResourceParam && resourceOptions[activeProvider].some(r => r.id === initialResourceParam)
            ? initialResourceParam
            : pickFirstAvailableResource(activeProvider),
    );

    useEffect(() => {
        if (!hasAvailableProvider) {
            return;
        }

        const providerChanged = !providers[activeProvider]?.available;
        if (providerChanged) {
            const next = pickFirstAvailableProvider();
            setActiveProvider(next);
            setActiveResource(pickFirstAvailableResource(next));
            setSearchParams({ provider: next, resource: pickFirstAvailableResource(next) });
            return;
        }

        if (!resourceOptions[activeProvider].some(r => r.id === activeResource)) {
            const nextResource = pickFirstAvailableResource(activeProvider);
            setActiveResource(nextResource);
            setSearchParams({ provider: activeProvider, resource: nextResource });
            return;
        }

        setSearchParams({ provider: activeProvider, resource: activeResource });
    }, [activeProvider, activeResource, providers, setSearchParams, hasAvailableProvider]);

    const handleProviderChange = (provider: Provider) => {
        if (provider === activeProvider) return;
        setActiveProvider(provider);
        setActiveResource(pickFirstAvailableResource(provider));
    };

    const handleResourceChange = (resource: Resource, enabled: boolean) => {
        if (!enabled) return;
        setActiveResource(resource);
    };

    const renderContent = () => {
        if (!hasAvailableProvider) {
            return <NotConfigured label={'Plugins'} reason={'No providers are configured for this server.'} />;
        }
        if (!providers[activeProvider].available) {
            return <NotConfigured label={providerLabels[activeProvider]} reason={providers[activeProvider].reason} />;
        }

        if (activeProvider === 'modrinth') {
            if (activeResource === 'mods') return <ModsContainer sourceOverride="modrinth" />;
            return <ComingSoon message={'Modrinth modpacks are coming soon.'} />;
        }

        if (activeProvider === 'curseforge') {
            if (activeResource === 'mods') return <ModsContainer sourceOverride="curseforge" />;
            return <ModpacksContainer />;
        }

        if (activeProvider === 'spiget') {
            if (!providers.spiget.available) {
                return <NotConfigured label={'Spiget'} reason={providers.spiget.reason} />;
            }
            if (activeResource === 'plugins') {
                return <ComingSoon message={'Spiget plugins will be available once configured.'} />;
            }
        }

        return null;
    };

    return (
        <PageContentBlock
            title={'Plugins'}
            description={'Browse providers and install mods, modpacks, or plugins from a unified experience.'}
            showFlashKey={'plugins'}
            header
        >
            <div css={tw`border-b border-neutral-700 mb-6 flex flex-wrap gap-2`}>
                {providerOrder.map(provider => {
                    const state = providers[provider];
                    const active = provider === activeProvider;
                    return (
                        <button
                            key={provider}
                            css={[
                                tw`px-4 py-2 font-medium transition-colors rounded-t`,
                                active
                                    ? tw`text-blue-400 border-b-2 border-blue-400`
                                    : tw`text-neutral-400 hover:text-neutral-200`,
                                !state.available && tw`opacity-50 cursor-not-allowed`,
                            ]}
                            onClick={() => state.available && handleProviderChange(provider)}
                            type="button"
                        >
                            {providerLabels[provider]}
                            {!state.available && ' (Not configured)'}
                        </button>
                    );
                })}
            </div>

            {providers[activeProvider].available ? (
                <div css={tw`mb-6 flex gap-2 border-b border-neutral-700`}>
                    {resourceOptions[activeProvider].map(resource => {
                        const active = resource.id === activeResource;
                        return (
                            <button
                                key={resource.id}
                                css={[
                                    tw`px-3 py-2 text-sm font-medium transition-colors rounded-t`,
                                    active
                                        ? tw`text-blue-300 border-b-2 border-blue-300`
                                        : tw`text-neutral-400 hover:text-neutral-200`,
                                    !resource.enabled && tw`opacity-50 cursor-not-allowed`,
                                ]}
                                onClick={() => handleResourceChange(resource.id, resource.enabled)}
                                type="button"
                            >
                                {resource.label}
                                {!resource.enabled && resource.comingSoon && ' (Coming soon)'}
                                {!resource.enabled && !resource.comingSoon && ' (Disabled)'}
                            </button>
                        );
                    })}
                </div>
            ) : (
                <div css={tw`mb-6`} />
            )}

            {renderContent()}
        </PageContentBlock>
    );
}
