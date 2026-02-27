import http from '@/api/http';

export type ProviderKey = 'modrinth' | 'curseforge' | 'spiget';
export type ContentType = 'mods' | 'modpacks' | 'plugins';

export interface PluginCapabilityResponse {
    mods: ProviderKey[];
    modpacks: ProviderKey[];
    plugins: ProviderKey[];
}

export const getPluginCapabilities = (uuid: string): Promise<PluginCapabilityResponse> =>
    http.get(`/api/client/servers/${uuid}/plugins/capabilities`).then(r => r.data);
