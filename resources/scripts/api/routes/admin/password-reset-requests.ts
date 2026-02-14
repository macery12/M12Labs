import http from '@/api/http';

export interface AdminPasswordResetRequest {
    id: number;
    user_id: number;
    user_email: string;
    user_username: string;
    discord_username?: string;
    contact_email?: string;
    reason: string;
    status: 'pending' | 'approved' | 'denied';
    admin_id?: number;
    admin_username?: string;
    admin_notes?: string;
    created_at: string;
    updated_at: string;
}

export const getAllPasswordResetRequests = async (status?: string): Promise<AdminPasswordResetRequest[]> => {
    const params = status ? { status } : {};
    const { data } = await http.get('/api/application/password-reset-requests', { params });
    
    // Handle JSON-API format: data is array of objects with attributes
    if (data.data && Array.isArray(data.data)) {
        return data.data.map((item: any) => item.attributes || item);
    }
    
    return data.data || [];
};

export const getPasswordResetRequestCount = async (): Promise<number> => {
    const { data } = await http.get('/api/application/password-reset-requests/count');
    return data.count || 0;
};

export const approvePasswordResetRequest = async (id: number, adminNotes?: string): Promise<any> => {
    const { data } = await http.post(`/api/application/password-reset-requests/${id}/approve`, { admin_notes: adminNotes });
    return data;
};

export const denyPasswordResetRequest = async (id: number, adminNotes?: string): Promise<void> => {
    await http.post(`/api/application/password-reset-requests/${id}/deny`, { admin_notes: adminNotes });
};
