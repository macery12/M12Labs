import http from '@/api/http';

export interface ActiveAlert {
    id: number;
    title?: string;
    content: string;
    type: 'success' | 'info' | 'warning' | 'danger';
    position: 'top-center' | 'bottom-right' | 'bottom-left' | 'center';
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

export const getActiveAlerts = async (): Promise<ActiveAlert[]> => {
    const { data } = await http.get('/api/client/alerts');
    return data;
};
