import http from '@/api/http';

export interface MolliePayment {
    id: string;
    checkout_url: string;
}

export interface MolliePaymentStatus {
    processed: boolean;
    failed: boolean;
    pending: boolean;
}

export const createMolliePayment = (id: number, couponId?: number, returnUrl?: string): Promise<MolliePayment> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/billing/products/${id}/mollie/payment`, {
            coupon_id: couponId,
            return_url: returnUrl,
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
