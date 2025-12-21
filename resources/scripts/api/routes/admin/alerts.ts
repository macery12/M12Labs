import http from '@/api/http';
import { AlertPosition, AlertType } from '@/state/everest';

export interface AlertSettings {
    enabled: boolean;
    type: AlertType;
    position: AlertPosition;
    content: string;
}

export interface Alert {
    id: number;
    title?: string;
    content: string;
    type: AlertType;
    position: AlertPosition;
    enabled: boolean;
    dismissible: boolean;
    link?: string;
    link_text?: string;
    priority: number;
    start_at?: string;
    end_at?: string;
    created_at: string;
    updated_at: string;
}

export interface CreateAlertData {
    title?: string;
    content: string;
    type: AlertType;
    position: AlertPosition;
    enabled?: boolean;
    dismissible?: boolean;
    link?: string;
    link_text?: string;
    priority?: number;
    start_at?: string;
    end_at?: string;
}

export interface UpdateAlertData {
    title?: string;
    content?: string;
    type?: AlertType;
    position?: AlertPosition;
    enabled?: boolean;
    dismissible?: boolean;
    link?: string;
    link_text?: string;
    priority?: number;
    start_at?: string;
    end_at?: string;
}

export const getAlerts = async (): Promise<Alert[]> => {
    const { data } = await http.get('/api/application/alerts');
    return data;
};

export const createAlert = async (alertData: CreateAlertData): Promise<Alert> => {
    const { data } = await http.post('/api/application/alerts', alertData);
    return data;
};

export const updateAlert = async (id: number, alertData: UpdateAlertData): Promise<Alert> => {
    const { data } = await http.patch(`/api/application/alerts/${id}`, alertData);
    return data;
};

export const deleteAlert = async (id: number): Promise<void> => {
    await http.delete(`/api/application/alerts/${id}`);
};

// Legacy function for backward compatibility
export const updateAlertSettings = async (settings: Partial<AlertSettings>): Promise<string> => {
    return new Promise((resolve, reject) => {
        http.patch(`/api/application/alerts`, settings)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
