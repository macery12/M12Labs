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
