import { Server } from '@definitions/server';
import http from '@/api/http';
import { BillingServerVariables } from '@/components/account/billing/order/PaymentButton';
import { Transformers } from '@/api/definitions/server';

export const processUnpaidOrder = (
    product: number,
    node?: number,
    renewal?: boolean,
    variables?: { key: string; value: string }[],
    server_id?: number,
): Promise<Server> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/billing/process/free`, { server_id, node, product, renewal, variables })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const renewFreeServer = (product: number, server_id: number): Promise<Server> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/billing/renew/free`, { product, server_id })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const createCheckoutSession = (
    product_id: number,
    node_id?: number,
    server_id?: number,
    variables?: BillingServerVariables[],
): Promise<string> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/billing/stripe/create`, { node_id, server_id, product_id, variables })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const processCheckoutSession = (session: string): Promise<Server> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/billing/stripe/process`, { session })
            .then(({ data }) => resolve(Transformers.toServer(data)))
            .catch(reject);
    });
};
