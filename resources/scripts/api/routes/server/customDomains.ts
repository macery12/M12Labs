import useSWR from 'swr';
import http from '@/api/http';
import { ServerContext } from '@/state/server';

export interface ServerCustomDomainRecord {
    id: number;
    domain_id: number;
    domain: string;
    subdomain: string;
    full_domain: string;
    port: number;
    protocol: 'tcp' | 'udp' | 'both';
    ssl_enabled: boolean;
    ssl_status: 'disabled' | 'pending' | 'issued' | 'failed';
    status: 'pending' | 'active' | 'failed';
    last_error: string | null;
    last_synced_at: string | null;
}

export const getServerCustomDomains = () => {
    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);

    return useSWR<ServerCustomDomainRecord[]>(
        ['server:custom-domains', uuid],
        async () => {
            const { data } = await http.get(`/api/client/servers/${uuid}/custom-domains`);

            return data.data || [];
        },
        { revalidateOnFocus: false },
    );
};

export const createServerCustomDomain = async (
    uuid: string,
    payload: {
        domain_id: number;
        subdomain: string;
        port: number;
        protocol: 'tcp' | 'udp' | 'both';
        ssl_enabled?: boolean;
    },
): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/custom-domains`, payload);
};

export const deleteServerCustomDomain = async (uuid: string, id: number): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/custom-domains/${id}`);
};

export const syncServerCustomDomains = async (uuid: string): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/custom-domains/sync`);
};
