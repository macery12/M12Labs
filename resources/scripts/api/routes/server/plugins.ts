import http from '@/api/http';

export type ProviderKey = 'modrinth' | 'curseforge' | 'spiget';
export type ContentType = 'mods' | 'modpacks' | 'plugins';

export interface PluginProviderAccessResponse {
    mods: ProviderKey[];
    modpacks: ProviderKey[];
    plugins: ProviderKey[];
    installed: boolean;
}

export const getPluginProviders = (uuid: string): Promise<PluginProviderAccessResponse> =>
    http.get(`/api/client/servers/${uuid}/plugins/providers`).then(r => r.data);
