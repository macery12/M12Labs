import http from '@/api/http';
import { Product } from '@definitions/account/billing';
import { Transformers } from '@definitions/account/billing';

export interface PlanChangeValidation {
    valid: boolean;
    message: string;
    violations?: {
        [key: string]: {
            current: number;
            limit: number;
            unit: string;
        };
    };
}

export interface PlanChangeResponse {
    success: boolean;
    message: string;
    server?: {
        id: number;
        uuid: string;
        billing_product_id: number;
        limits: {
            memory: number;
            disk: number;
            cpu: number;
            database: number;
            backup: number;
            allocation: number;
        };
    };
}

/**
 * Get available plans for a server.
 */
export const getAvailablePlans = (serverUuid: string): Promise<Product[]> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${serverUuid}/billing/plans`)
            .then(({ data }) => resolve((data.data || []).map(Transformers.toProduct)))
            .catch(reject);
    });
};

/**
 * Validate if a plan change is allowed.
 */
export const validatePlanChange = (serverUuid: string, productId: number): Promise<PlanChangeValidation> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${serverUuid}/billing/plans/${productId}/validate`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};

/**
 * Change the server's plan.
 */
export const changePlan = (serverUuid: string, productId: number): Promise<PlanChangeResponse> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${serverUuid}/billing/plans/${productId}/change`)
            .then(({ data }) => resolve(data))
            .catch(reject);
    });
};
