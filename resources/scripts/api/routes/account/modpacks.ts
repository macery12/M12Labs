import http from '@/api/http';
import {
    ModpackSearchParams,
    ModpackSearchResponse,
    ModpackResponse,
    ModpackFilesResponse,
    ModpackFileParams,
    MinecraftVersion,
    ModLoaderType,
} from '@/api/routes/server/modpacks';

export interface InstallModpackParams {
    serverId: string;
    modpackId: number;
    fileId?: number;
}

/**
 * Search for modpacks across CurseForge (account-level, not server-specific)
 */
export const searchModpacks = (params: ModpackSearchParams): Promise<ModpackSearchResponse> => {
    return new Promise((resolve, reject) => {
        http.get('/api/client/account/modpacks/search', { params })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

/**
 * Get details about a specific modpack (account-level)
 */
export const getModpack = (modpackId: number): Promise<ModpackResponse> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/account/modpacks/${modpackId}`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

/**
 * Get files for a specific modpack (account-level)
 */
export const getModpackFiles = (modpackId: number, params: ModpackFileParams): Promise<ModpackFilesResponse> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/account/modpacks/${modpackId}/files`, { params })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

/**
 * Get servers that are compatible with modpack installation
 */
export const getCompatibleServers = (): Promise<{ servers: Array<{ uuid: string; name: string; eggId: number }> }> => {
    return new Promise((resolve, reject) => {
        http.get('/api/client/account/modpacks/compatible-servers')
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

/**
 * Install a modpack to a specific server
 * This updates the server's environment variables and triggers a reinstall
 */
export const installModpackToServer = (params: InstallModpackParams): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.post('/api/client/account/modpacks/install', params, {
            timeout: 30000, // 30 seconds for the API call
        })
            .then(() => resolve())
            .catch(reject);
    });
};

/**
 * Get Minecraft versions (account-level)
 */
export const getMinecraftVersions = (): Promise<{ data: MinecraftVersion[] }> => {
    return new Promise((resolve, reject) => {
        http.get('/api/client/account/modpacks/minecraft/versions')
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

/**
 * Get mod loader types (account-level)
 */
export const getModLoaderTypes = (): Promise<{ data: ModLoaderType[] }> => {
    return new Promise((resolve, reject) => {
        http.get('/api/client/account/modpacks/minecraft/loaders')
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

/**
 * Get current modpack info for a server
 */
export const getServerModpackInfo = (
    serverId: string,
): Promise<{
    projectId?: string;
    versionId?: string;
    modpackName?: string;
}> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/account/modpacks/server/${serverId}/info`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
