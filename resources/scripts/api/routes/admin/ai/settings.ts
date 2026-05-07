import http from '@/api/http';

export interface AISettings {
    key?: string | boolean;
    enabled?: boolean;
    user_access?: boolean;
    endpoint?: string;
    model?: string;
    mode?: string;
    max_tokens?: number;
    temperature?: number;
    system_prompt?: string;
}

export const updateSettings = (settings: AISettings): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.put(`/api/application/ai/settings`, settings)
            .then(() => resolve())
            .catch(reject);
    });
};

export interface ConnectionTestResult {
    status: 'ok' | 'error';
    latency_ms?: number;
    message?: string;
}

export const testConnection = (): Promise<ConnectionTestResult> => {
    return http.get('/api/application/ai/test').then(({ data }) => data);
};

export interface DailyStat { date: string; requests: number }
export interface TopUser { username: string; email: string | null; requests: number }

export interface AIStats {
    all_time: { total_requests: number; successful: number; errors: number; total_tokens: number; avg_latency_ms: number | null };
    last_24h: { requests: number; tokens: number };
    last_7d: { requests: number; tokens: number };
    daily_series: DailyStat[];
    top_users: TopUser[];
    source_breakdown: Record<string, number>;
}

export const getStats = (): Promise<AIStats> => {
    return http.get('/api/application/ai/stats').then(({ data }) => data);
};
