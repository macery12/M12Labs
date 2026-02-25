import http from '@/api/http';

export interface ModsSettings {
    curseforge_api_key?: string | boolean;
    enabled?: boolean;
    default_source?: string;
    spiget_enabled?: boolean;
}

export const updateSettings = (settings: ModsSettings): Promise<void> =>
    http.put(`/api/application/plugins/settings`, settings).then(() => {});

export interface RateLimitUsage {
    requests_this_minute: number;
    requests_this_hour: number;
    limit_per_minute: number;
    limit_per_hour: number;
}

export interface ModsAnalytics {
    totals: {
        installs: number;
        by_provider: { modrinth: number; curseforge: number; spiget: number };
        failures: number;
        retries: number;
        bandwidth_bytes: number;
        bandwidth_bytes_24h: number;
    };
    trends: {
        last_24h: Array<{ timestamp: string; installs: number }>;
        last_7d: Array<{ date: string; installs: number }>;
    };
    provider_health: Record<
        string,
        {
            enabled: boolean;
            rate_limit: RateLimitUsage | null;
            denied_by_policy: number;
        }
    >;
}

export const getModsAnalytics = (): Promise<ModsAnalytics> =>
    http.get(`/api/application/plugins/analytics`).then(({ data }) => data);

export const resetCurseForgeKey = (): Promise<void> =>
    http.delete(`/api/application/plugins/key`).then(() => {});
