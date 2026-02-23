import http from '@/api/http';

export interface ModsSettings {
    curseforge_api_key?: string | boolean;
    enabled?: boolean;
    default_source?: string;
    spiget_enabled?: boolean;
}

export const updateSettings = (settings: ModsSettings): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.put(`/api/application/mods/settings`, settings)
            .then(() => resolve())
            .catch(reject);
    });
};

export interface RateLimitUsage {
    requests_this_minute: number;
    requests_this_hour: number;
    limit_per_minute: number;
    limit_per_hour: number;
}

export interface ModsAnalytics {
    curseforge_rate_limit: RateLimitUsage;
    modrinth_rate_limit: RateLimitUsage;
}

export const getModsAnalytics = (): Promise<ModsAnalytics> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/application/mods/analytics`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const resetCurseForgeKey = (): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.delete(`/api/application/mods/key`)
            .then(() => resolve())
            .catch(reject);
    });
};
