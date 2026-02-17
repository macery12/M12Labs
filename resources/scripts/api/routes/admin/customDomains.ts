import http from '@/api/http';

export interface AdminCustomDomain {
    id: number;
    domain: string;
    cloudflare_zone_id: string | null;
    wildcard_enabled: boolean;
    enabled: boolean;
    created_at?: string;
    updated_at?: string;
}

export interface CustomDomainSettings {
    cloudflare_token: string;
}

export interface CreateAdminCustomDomainPayload {
    domain: string;
    cloudflare_zone_id?: string | null;
    wildcard_enabled?: boolean;
    enabled?: boolean;
}

export interface UpdateAdminCustomDomainPayload {
    domain?: string;
    cloudflare_zone_id?: string | null;
    wildcard_enabled?: boolean;
    enabled?: boolean;
}

export const getCustomDomains = async (): Promise<AdminCustomDomain[]> => {
    const { data } = await http.get('/api/application/custom-domains');

    return data.data || [];
};

export const createCustomDomain = async (payload: CreateAdminCustomDomainPayload): Promise<AdminCustomDomain> => {
    const { data } = await http.post('/api/application/custom-domains', payload);

    return data.data;
};

export const updateCustomDomain = async (
    id: number,
    payload: UpdateAdminCustomDomainPayload,
): Promise<AdminCustomDomain> => {
    const { data } = await http.patch(`/api/application/custom-domains/${id}`, payload);

    return data.data;
};

export const deleteCustomDomain = async (id: number): Promise<void> => {
    await http.delete(`/api/application/custom-domains/${id}`);
};

export const getCustomDomainSettings = async (): Promise<CustomDomainSettings> => {
    const { data } = await http.get('/api/application/custom-domains/settings');

    return data.data;
};

export const updateCustomDomainSettings = async (settings: CustomDomainSettings): Promise<void> => {
    await http.put('/api/application/custom-domains/settings', settings);
};
