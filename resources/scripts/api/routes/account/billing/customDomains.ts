import http from '@/api/http';

export interface AvailableCustomDomain {
    id: number;
    domain: string;
    wildcard_enabled: boolean;
}

export const getAvailableCustomDomains = async (): Promise<AvailableCustomDomain[]> => {
    const { data } = await http.get('/api/client/billing/custom-domains/options');

    return data.data || [];
};
