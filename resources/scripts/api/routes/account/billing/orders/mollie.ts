import http from '@/api/http';

export interface MolliePayment {
    id: string;
    token: string;
    checkout_url: string;
}

export interface MolliePaymentStatus {
    processed: boolean;
    failed: boolean;
    pending: boolean;
}

export interface MolliePaymentFromToken {
    payment_id: string;
    user_id: number;
}

export const createMolliePayment = (
    id: number,
    couponId?: number,
    returnUrl?: string,
    serverId?: number,
    renewal?: boolean,
): Promise<MolliePayment> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/billing/products/${id}/mollie/payment`, {
            coupon_id: couponId,
            return_url: returnUrl,
            server_id: serverId,
            renewal,
        })
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const updateMolliePayment = ({
    id,
    paymentId,
    nodeId,
    vars,
    serverId,
    renewal,
    couponId,
    eggId,
    name,
    domainPayload,
}: {
    id: number;
    paymentId: string;
    nodeId?: number;
    vars?: Array<{ key: string; value: string }>;
    serverId?: number;
    renewal?: boolean;
    couponId?: number;
    eggId?: number;
    name: string;
    domainPayload?: Array<{
        domain_id: number;
        subdomain: string;
        record_type?: 'srv' | 'cname';
    }>;
}): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.put(`/api/client/billing/products/${id}/mollie/payment`, {
            payment_id: paymentId,
            node_id: nodeId,
            variables: vars,
            server_id: serverId,
            renewal,
            coupon_id: couponId,
            egg_id: eggId,
            name,
            domain_payload: domainPayload,
        })
            .then(() => resolve())
            .catch(reject);
    });
};

export const checkMolliePaymentStatus = (paymentId?: string | null): Promise<MolliePaymentStatus> => {
    return new Promise((resolve, reject) => {
        const url = paymentId
            ? `/api/client/billing/mollie/status?payment_id=${paymentId}`
            : `/api/client/billing/mollie/status`;

        http.get(url)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

export const getPaymentIdFromToken = (token: string): Promise<MolliePaymentFromToken> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/billing/mollie/token/${token}`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
