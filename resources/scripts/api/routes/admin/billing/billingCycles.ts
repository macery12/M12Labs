import http from '@/api/http';
import { BillingCycleWithPrice } from '@definitions/admin';

export interface MultiplierRanges {
    multiplier_up: {
        min: number;
        max: number;
        suggested: number;
        description: string;
    };
    multiplier_down: {
        min: number;
        max: number;
        suggested: number;
        description: string;
    };
}

export const getBillingCycles = async (categoryId: number, productId: number): Promise<BillingCycleWithPrice[]> => {
    const { data } = await http.get(
        `/api/application/billing/categories/${categoryId}/products/${productId}/billing-cycles`,
    );
    return data.data;
};

export const syncBillingCycles = (
    categoryId: number,
    productId: number,
    cycles: Array<{ days: number; is_enabled: boolean }>,
): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/application/billing/categories/${categoryId}/products/${productId}/billing-cycles/sync`, {
            cycles,
        })
            .then(() => resolve())
            .catch(reject);
    });
};

export const deleteBillingCycle = (categoryId: number, productId: number, cycleId: number): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.delete(`/api/application/billing/categories/${categoryId}/products/${productId}/billing-cycles/${cycleId}`)
            .then(() => resolve())
            .catch(reject);
    });
};

export const getMultiplierRanges = async (): Promise<MultiplierRanges> => {
    const { data } = await http.get('/api/application/billing/multiplier-ranges');
    return data.data;
};
