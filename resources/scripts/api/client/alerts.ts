import http from '@/api/http';

export interface ActiveAlert {
    id: number;
    title?: string;
    content: string;
    type: 'success' | 'info' | 'warning' | 'danger';
    position: 'top-center' | 'slide-out' | 'top-right-banner' | 'center';
    scope: 'global' | 'dashboard' | 'server' | 'billing' | 'account' | 'admin';
    enabled: boolean;
    dismissible: boolean;
    show_button: boolean;
    button_text?: string;
    button_position: 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left';
    link?: string;
    link_text?: string;
    priority: number;
    start_at?: string;
    end_at?: string;
    created_at: string;
    updated_at: string;
}

export const getActiveAlerts = async (scope = 'global'): Promise<ActiveAlert[]> => {
    const { data } = await http.get('/api/client/alerts', {
        params: { scope },
    });
    return data;
};
