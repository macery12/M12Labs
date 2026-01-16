import http from '@/api/http';

export interface ModpackSearchParams {
    searchFilter?: string;
    sortField?: string;
    sortOrder?: string;
    gameVersion?: string;
    modLoaderType?: number;
    pageSize?: number;
    index?: number;
}

export interface ModpackFileParams {
    gameVersion?: string;
    modLoaderType?: number;
    pageSize?: number;
    index?: number;
}

export interface CurseForgeAuthor {
    id: number;
    name: string;
    url: string;
}

export interface CurseForgeFile {
    id: number;
    gameId: number;
    modId: number;
    isAvailable: boolean;
    displayName: string;
    fileName: string;
    releaseType: number;
    fileStatus: number;
    hashes: Array<{ value: string; algo: number }>;
    fileDate: string;
    fileLength: number;
    downloadCount: number;
    downloadUrl: string;
    gameVersions: string[];
    sortableGameVersions: Array<{
        gameVersionName: string;
        gameVersionPadded: string;
        gameVersion: string;
        gameVersionReleaseDate: string;
        gameVersionTypeId: number;
    }>;
    dependencies: Array<{ modId: number; relationType: number }>;
    alternateFileId: number;
    isServerPack: boolean;
    fileFingerprint: number;
    modules: Array<{ name: string; fingerprint: number }>;
}

export interface CurseForgeCategory {
    id: number;
    gameId: number;
    name: string;
    slug: string;
    url: string;
    iconUrl: string;
    dateModified: string;
    isClass: boolean;
    classId: number;
    parentCategoryId: number;
}

export interface CurseForgeModpack {
    id: number;
    gameId: number;
    name: string;
    slug: string;
    links: {
        websiteUrl: string;
        wikiUrl: string;
        issuesUrl: string;
        sourceUrl: string;
    };
    summary: string;
    status: number;
    downloadCount: number;
    isFeatured: boolean;
    primaryCategoryId: number;
    categories: CurseForgeCategory[];
    classId: number;
    authors: CurseForgeAuthor[];
    logo: {
        id: number;
        modId: number;
        title: string;
        description: string;
        thumbnailUrl: string;
        url: string;
    };
    screenshots: Array<{
        id: number;
        modId: number;
        title: string;
        description: string;
        thumbnailUrl: string;
        url: string;
    }>;
    mainFileId: number;
    latestFiles: CurseForgeFile[];
    latestFilesIndexes: Array<{
        gameVersion: string;
        fileId: number;
        filename: string;
        releaseType: number;
        gameVersionTypeId: number;
        modLoader: number;
    }>;
    dateCreated: string;
    dateModified: string;
    dateReleased: string;
    allowModDistribution: boolean;
    gamePopularityRank: number;
}

export interface ModpackSearchResponse {
    data: CurseForgeModpack[];
    pagination: {
        index: number;
        pageSize: number;
        resultCount: number;
        totalCount: number;
    };
}

export interface ModpackResponse {
    data: CurseForgeModpack;
}

export interface ModpackFilesResponse {
    data: CurseForgeFile[];
    pagination: {
        index: number;
        pageSize: number;
        resultCount: number;
        totalCount: number;
    };
}

export interface MinecraftVersion {
    id: number;
    gameVersionId: number;
    versionString: string;
    jarDownloadUrl: string;
    jsonDownloadUrl: string;
    approved: boolean;
    dateModified: string;
    gameVersionTypeId: number;
    gameVersionStatus: number;
    gameVersionTypeStatus: number;
}

export interface ModLoaderType {
    id: number;
    gameVersionTypeId: number;
    name: string;
    slug: string;
}

export const searchModpacks = (uuid: string, params: ModpackSearchParams): Promise<ModpackSearchResponse> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/modpacks/search`, { params })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const getModpack = (uuid: string, modpackId: number): Promise<ModpackResponse> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/modpacks/${modpackId}`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const getModpackFiles = (uuid: string, modpackId: number, params: ModpackFileParams): Promise<ModpackFilesResponse> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/modpacks/${modpackId}/files`, { params })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const downloadModpack = (uuid: string, modpackId: number, fileId: number): Promise<void> => {
    return new Promise((resolve, reject) => {
        // Use extended timeout for modpack downloads (10 minutes) as they can take a long time
        // with many mods to download and extract
        http.post(`/api/client/servers/${uuid}/modpacks/${modpackId}/files/${fileId}/download`, {}, {
            timeout: 600000, // 10 minutes
        })
            .then(() => resolve())
            .catch(reject);
    });
};

export const getMinecraftVersions = (uuid: string): Promise<{ data: MinecraftVersion[] }> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/modpacks/minecraft/versions`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const getModLoaderTypes = (uuid: string): Promise<{ data: ModLoaderType[] }> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/modpacks/minecraft/loaders`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
