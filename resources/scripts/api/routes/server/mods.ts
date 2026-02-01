import http from '@/api/http';

export interface ModSearchParams {
    searchFilter?: string;
    sortField?: string;
    sortOrder?: string;
    gameVersion?: string;
    modLoaderType?: number;
    pageSize?: number;
    index?: number;
    source?: string;
}

export interface ModFileParams {
    gameVersion?: string;
    modLoaderType?: number;
    pageSize?: number;
    index?: number;
    source?: string;
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

export interface CurseForgeMod {
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

export interface ModSearchResponse {
    data: CurseForgeMod[];
    pagination: {
        index: number;
        pageSize: number;
        resultCount: number;
        totalCount: number;
    };
}

export interface ModResponse {
    data: CurseForgeMod;
}

export interface ModFilesResponse {
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

export const searchMods = (uuid: string, params: ModSearchParams): Promise<ModSearchResponse> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/mods/search`, { params })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const getMod = (uuid: string, modId: number | string, source?: string): Promise<ModResponse> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/mods/${modId}`, { params: { source } })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const getModFiles = (uuid: string, modId: number | string, params: ModFileParams): Promise<ModFilesResponse> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/mods/${modId}/files`, { params })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const downloadMod = (uuid: string, modId: number | string, fileId: number | string, source?: string): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/mods/${modId}/files/${fileId}/download`, { source })
            .then(() => resolve())
            .catch(reject);
    });
};

export const getMinecraftVersions = (uuid: string, source?: string): Promise<{ data: MinecraftVersion[] }> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/mods/minecraft/versions`, { params: { source } })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const getModLoaderTypes = (uuid: string, source?: string): Promise<{ data: ModLoaderType[] }> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/mods/minecraft/loaders`, { params: { source } })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
