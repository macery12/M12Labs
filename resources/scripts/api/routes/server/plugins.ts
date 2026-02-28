import http from '@/api/http';

export type ProviderKey = 'modrinth' | 'curseforge' | 'spigot';
export type ContentType = 'mods' | 'modpacks' | 'plugins';

export interface PluginCapabilityResponse {
    mods: ProviderKey[];
    modpacks: ProviderKey[];
    plugins: ProviderKey[];
}

export const getPluginCapabilities = (uuid: string): Promise<PluginCapabilityResponse> =>
    http.get(`/api/client/servers/${uuid}/plugins/capabilities`).then(r => r.data);

export type InstalledAddonType = 'mod' | 'plugin';

export interface InstalledAddon {
    id: number;
    name: string;
    displayName: string;
    path: string;
    size: number;
    modifiedAt: Date | null;
    type: InstalledAddonType;
    disabled: boolean;
    loader?: string | null;
    version?: string | null;
    description?: string | null;
    authors: string[];
    iconUrl?: string | null;
    iconId: string | null;
    identityKey?: string | null;
    parsing?: boolean;
    parseError?: string | null;
    stableId?: string | null;
}

export interface InstalledAddonResponse {
    mods: InstalledAddon[];
    plugins: InstalledAddon[];
    stats?: {
        mods?: number;
        plugins?: number;
        disabled?: number;
        total?: number;
    };
    scanInProgress?: boolean;
}

const toInstalledAddon = (raw: any): InstalledAddon => ({
    id: Number(raw?.id ?? 0),
    name: raw?.name ?? '',
    displayName: raw?.display_name ?? raw?.name ?? '',
    path: raw?.path ?? '',
    size: Number(raw?.size ?? 0),
    modifiedAt: raw?.modified_at ? new Date(raw.modified_at) : null,
    type: (raw?.type ?? 'mod') as InstalledAddonType,
    disabled: Boolean(raw?.disabled),
    loader: raw?.loader ?? null,
    version: raw?.version ?? null,
    description: raw?.description ?? null,
    authors: Array.isArray(raw?.authors) ? raw.authors : raw?.authors ? [raw.authors] : [],
    iconUrl: raw?.icon_url ?? raw?.iconUrl ?? null,
    iconId: raw?.icon_id ?? raw?.iconId ?? null,
    identityKey: raw?.identity_key ?? null,
    parsing: Boolean(raw?.parsing),
    parseError: raw?.parseError ?? raw?.parse_error ?? null,
    stableId: raw?.stable_id ?? raw?.stableId ?? null,
});

export const getInstalledAddons = (uuid: string): Promise<InstalledAddonResponse> =>
    http.get(`/api/client/servers/${uuid}/plugins/installed`).then(r => ({
        mods: (r.data?.mods ?? []).map(toInstalledAddon),
        plugins: (r.data?.plugins ?? []).map(toInstalledAddon),
        stats: r.data?.stats,
        scanInProgress: Boolean(r.data?.scanInProgress),
    }));

export const toggleInstalledAddons = (uuid: string, paths: string[], disabled: boolean): Promise<void> =>
    http.post(`/api/client/servers/${uuid}/plugins/installed/toggle`, { paths, disabled }).then(() => undefined);

export const deleteInstalledAddons = (uuid: string, paths: string[]): Promise<void> =>
    http.post(`/api/client/servers/${uuid}/plugins/installed/delete`, { paths }).then(() => undefined);

export const rescanInstalledAddons = (uuid: string): Promise<void> =>
    http.post(`/api/client/servers/${uuid}/plugins/installed/rescan`).then(() => undefined);
