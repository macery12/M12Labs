import http from '@/api/http';

export interface ResendSettings {
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

export const updateSettings = (settings: ResendSettings): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.put(`/api/application/email/settings`, settings)
            .then(() => resolve())
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
