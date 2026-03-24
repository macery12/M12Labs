import http from '@/api/http';
import { Product, Transformers } from '@definitions/account/billing';

export const getUpgradeOptions = (id: string): Promise<Product[]> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${id}/upgrade`)
            .then(({ data }) => resolve((data.data || []).map(Transformers.toProduct)))
            .catch(reject);
    });
};

export const getUpgradeCharge = (id: string, product_id: number): Promise<number> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${id}/upgrade/charge`, { product_id })
            .then(({ data }) => resolve(data.charge))
            .catch(reject);
    });
};

export const processUpgrade = (id: string, product_id: number): Promise<string> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${id}/upgrade`, { product_id })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
