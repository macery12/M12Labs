import http from '@/api/http';

export interface ResendSettings {
    enabled: boolean;
    api_key: boolean; // true if key exists, false otherwise
    from_email: string;
    from_name: string;
    reply_to: string;
}

export interface ResendSettingsUpdate {
    enabled?: boolean;
    api_key?: string;
    from_email?: string;
    from_name?: string;
    reply_to?: string;
}

export interface SendTestEmailRequest {
    to: string;
}

export interface SendCustomEmailRequest {
    to: string;
    subject: string;
    html: string;
    text?: string;
}

export interface EmailResponse {
    success: boolean;
    message_id?: string;
    error?: string;
}

export const getSettings = (): Promise<ResendSettings> => {
    return new Promise((resolve, reject) => {
        http.get<ResendSettings>(`/api/application/email/settings`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const updateSettings = (settings: ResendSettingsUpdate): Promise<ResendSettings> => {
    return new Promise((resolve, reject) => {
        http.put<ResendSettings>(`/api/application/email/settings`, settings)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const sendTestEmail = (data: SendTestEmailRequest): Promise<EmailResponse> => {
    return new Promise((resolve, reject) => {
        http.post<EmailResponse>(`/api/application/email/test`, data)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const sendCustomEmail = (data: SendCustomEmailRequest): Promise<EmailResponse> => {
    return new Promise((resolve, reject) => {
        http.post<EmailResponse>(`/api/application/email/send`, data)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
