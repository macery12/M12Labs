import { lazy, Suspense, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Boxes } from 'lucide-react';
import { m, td } from '@/i18n/messages';
import { useServer } from '@/components/server/ServerContext';
import { useFlags } from '@/state/flags';
import { Spinner } from '@/components/ui/Spinner';
import { getPluginCapabilities, getServerModsConfig, type InstalledContentType, type ProviderKey, type Source } from '@/api/mods';
import { getQueue } from '@/api/modQueue';
import { InstalledAddons } from './components/InstalledAddons';
import { queueKey } from './components/queueKey';
import { providerLabel } from './modMeta';

const ModBrowser = lazy(() => import('./components/ModBrowser').then(module => ({ default: module.ModBrowser })));
const QueueTab = lazy(() => import('./components/QueueTab').then(module => ({ default: module.QueueTab })));
const ModpackBrowser = lazy(() =>
    import('./components/modpacks/ModpackBrowser').then(module => ({ default: module.ModpackBrowser })),
);

type Tab = 'installed' | 'mods' | 'plugins' | 'modpacks' | 'queue';

const LAST_KEY = 'marketplace:last';

// Mounted at the server `marketplace/*` splat. Owns the tab + provider state
// (mirrored to the URL for deep-linking and localStorage for return visits),
// the capability/detection queries, and the live queue badge.
export default function MarketplaceSection() {
    const server = useServer();
    const serverId = server.id;
    const modsFlag = useFlags(s => s.everest?.mods) as
        | { enabled: boolean; curseforge?: { enabled?: boolean; configured?: boolean } }
        | undefined;
    const modpacksEnabled = !!(modsFlag?.curseforge?.enabled && modsFlag?.curseforge?.configured);

    const [params, setParams] = useSearchParams();

    const capsQ = useQuery({
        queryKey: ['mods', serverId, 'capabilities'],
        queryFn: () => getPluginCapabilities(serverId),
        staleTime: 5 * 60_000,
    });

    const configQ = useQuery({
        queryKey: ['mods', serverId, 'server-config'],
        queryFn: () => getServerModsConfig(serverId),
        staleTime: 5 * 60_000,
    });

    // Poll the queue for the header badge (active downloads).
    const queueQ = useQuery({
        queryKey: queueKey(serverId),
        queryFn: () => getQueue(serverId),
        refetchInterval: 8_000,
    });
    const activeCount = (queueQ.data ?? []).filter(i => i.status === 'pending' || i.status === 'downloading').length;

    const caps = capsQ.data;

    // What this egg actually loads, from its name: Paper/Spigot/… take plugins,
    // Forge/Fabric/… take mods. Null when the egg doesn't say.
    const detected = configQ.data;
    const kind: InstalledContentType | null = detected?.detectedLoader
        ? 'mods'
        : detected?.detectedPlatform
          ? 'plugins'
          : null;

    // Available tabs depend on provider capabilities + the CurseForge gate.
    // The type this server runs comes first: a Paper server opened on "Mods".
    const tabs = useMemo<Tab[]>(() => {
        const content: Tab[] = [];
        if ((caps?.mods?.length ?? 0) > 0) content.push('mods');
        if ((caps?.plugins?.length ?? 0) > 0) content.push('plugins');
        if (kind === 'plugins') content.reverse();
        const list: Tab[] = ['installed', ...content];
        if (modpacksEnabled) list.push('modpacks');
        list.push('queue');
        return list;
    }, [caps, modpacksEnabled, kind]);

    // Resolve the active tab: URL → localStorage → first available.
    const urlTab = params.get('type') as Tab | null;
    const stored = (() => {
        try {
            return JSON.parse(localStorage.getItem(LAST_KEY) || '{}') as { tab?: Tab; source?: Source };
        } catch {
            return {};
        }
    })();
    const [tab, setTab] = useState<Tab>(urlTab ?? stored.tab ?? 'installed');

    // Provider (source) for the mods/plugins browsers.
    const providers: ProviderKey[] = useMemo(
        () => (tab === 'plugins' ? caps?.plugins ?? [] : caps?.mods ?? []),
        [tab, caps],
    );
    const urlSource = params.get('provider') as Source | null;
    const [source, setSource] = useState<Source>(urlSource ?? stored.source ?? 'modrinth');

    // Keep the active tab valid once capabilities load.
    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect -- intentional effect: syncs state to prop/query/filter changes
        if (caps && !tabs.includes(tab)) setTab(tabs[0] ?? 'installed');
    }, [caps, tabs, tab]);

    // Ensure the selected source is one the current resource supports.
    useEffect(() => {
        const first = providers[0];
        if ((tab === 'mods' || tab === 'plugins') && first && !providers.includes(source)) {
            // eslint-disable-next-line react-hooks/set-state-in-effect -- intentional effect: syncs state to prop/query/filter changes
            setSource(first);
        }
    }, [tab, providers, source]);

    // Persist to URL + localStorage.
    useEffect(() => {
        const next = new URLSearchParams(params);
        next.set('type', tab);
        if (tab === 'mods' || tab === 'plugins') next.set('provider', source);
        else next.delete('provider');
        setParams(next, { replace: true });
        localStorage.setItem(LAST_KEY, JSON.stringify({ tab, source }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab, source]);

    // Wait for detection too, so Installed doesn't flash the wrong type.
    if (capsQ.isLoading || configQ.isLoading) {
        return (
            <div className="flex flex-1 items-center justify-center py-24">
                <Spinner className="h-6 w-6" />
            </div>
        );
    }

    return (
        <div className="flex min-h-[calc(100vh-9rem)] flex-col gap-5">
            <header>
                <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
                    <Boxes className="h-6 w-6 text-[var(--brand)]" />
                    {m['server.mods.title']()}
                </h1>
                <p className="mt-1 text-sm text-[var(--color-ink-muted)]">{m['server.mods.subtitle']()}</p>
            </header>

            {/* Primary tabs */}
            <nav className="flex flex-wrap gap-1 border-b border-[var(--color-border)]">
                {tabs.map(t => (
                    <button
                        key={t}
                        type="button"
                        onClick={() => setTab(t)}
                        className={`relative px-3 py-2 text-sm font-medium transition-colors ${
                            tab === t
                                ? 'text-[var(--color-ink)]'
                                : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]'
                        }`}
                    >
                        {td(`server.mods.tab.${t}`)}
                        {/* Active downloads; this used to be a second "Queue" button in the header. */}
                        {t === 'queue' && activeCount > 0 && (
                            <span className="ml-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[var(--brand)]/20 px-1.5 text-[11px] font-semibold text-[var(--brand)]">
                                {activeCount}
                            </span>
                        )}
                        {tab === t && (
                            <span className="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-[var(--brand)]" />
                        )}
                    </button>
                ))}
            </nav>

            {/* Provider selector for mods/plugins with >1 provider */}
            {(tab === 'mods' || tab === 'plugins') && providers.length > 1 && (
                <div className="inline-flex w-fit overflow-hidden rounded-lg border border-[var(--color-border-strong)]">
                    {providers.map(p => (
                        <button
                            key={p}
                            type="button"
                            onClick={() => setSource(p)}
                            className={`px-3 py-1.5 text-sm font-medium transition-colors ${
                                source === p
                                    ? 'bg-[var(--brand)]/15 text-[var(--color-ink)]'
                                    : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-2)]'
                            }`}
                        >
                            {providerLabel(p)}
                        </button>
                    ))}
                </div>
            )}

            <Suspense fallback={<div className="flex flex-1 items-center justify-center py-24"><Spinner className="h-6 w-6" /></div>}>
                <div className="flex min-h-0 flex-1 flex-col">
                    {tab === 'installed' && (
                        <InstalledAddons
                            serverId={serverId}
                            kind={kind}
                            browse={{
                                mods: tabs.includes('mods') ? () => setTab('mods') : undefined,
                                plugins: tabs.includes('plugins') ? () => setTab('plugins') : undefined,
                            }}
                        />
                    )}
                    {tab === 'mods' && (
                        <ModBrowser
                            key={`mods-${source}`}
                            serverId={serverId}
                            source={source}
                            resource="mods"
                            detected={configQ.data ?? null}
                        />
                    )}
                    {tab === 'plugins' && (
                        <ModBrowser
                            key={`plugins-${source}`}
                            serverId={serverId}
                            source={source}
                            resource="plugins"
                            detected={configQ.data ?? null}
                        />
                    )}
                    {tab === 'modpacks' && <ModpackBrowser serverId={serverId} />}
                    {tab === 'queue' && <QueueTab serverId={serverId} />}
                </div>
            </Suspense>
        </div>
    );
}
