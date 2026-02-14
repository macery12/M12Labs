import http from '@/api/http';

export interface PasswordResetRequest {
    id: number;
    user_id: number;
    discord_username?: string;
    contact_email?: string;
    reason: string;
    status: 'pending' | 'approved' | 'denied';
    admin_id?: number;
    admin_notes?: string;
    created_at: string;
    updated_at: string;
}

export interface CreatePasswordResetRequestData {
    discord_username?: string;
    contact_email?: string;
    reason: string;
}

export const createPasswordResetRequest = async (data: CreatePasswordResetRequestData): Promise<void> => {
    await http.post('/api/client/account/password-reset-requests', data);
};

export const getMyPasswordResetRequests = async (): Promise<PasswordResetRequest[]> => {
    const { data } = await http.get('/api/client/account/password-reset-requests');
    return data.data || [];
};
