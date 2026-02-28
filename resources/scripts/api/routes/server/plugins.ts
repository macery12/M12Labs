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
    name: string;
    displayName: string;
    path: string;
    size: number;
    modifiedAt: Date | null;
    type: InstalledAddonType;
    disabled: boolean;
}

export interface InstalledAddonResponse {
    mods: InstalledAddon[];
    plugins: InstalledAddon[];
}

const toInstalledAddon = (raw: any): InstalledAddon => ({
    name: raw?.name ?? '',
    displayName: raw?.display_name ?? raw?.name ?? '',
    path: raw?.path ?? '',
    size: Number(raw?.size ?? 0),
    modifiedAt: raw?.modified_at ? new Date(raw.modified_at) : null,
    type: (raw?.type ?? 'mod') as InstalledAddonType,
    disabled: Boolean(raw?.disabled),
});

export const getInstalledAddons = (uuid: string): Promise<InstalledAddonResponse> =>
    http.get(`/api/client/servers/${uuid}/plugins/installed`).then(r => ({
        mods: (r.data?.mods ?? []).map(toInstalledAddon),
        plugins: (r.data?.plugins ?? []).map(toInstalledAddon),
    }));
