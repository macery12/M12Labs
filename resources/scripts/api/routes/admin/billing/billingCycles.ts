import http from '@/api/http';
import { BillingCycleWithPrice } from '@definitions/admin';

export interface MultiplierRanges {
    // Deprecated - not used anymore
}

export const getBillingCycles = async (categoryId: number, productId: number): Promise<BillingCycleWithPrice[]> => {
    console.log('getBillingCycles called:', {
        categoryId,
        productId,
        url: `/api/application/billing/categories/${categoryId}/products/${productId}/billing-cycles`,
    });

    const { data } = await http.get(
        `/api/application/billing/categories/${categoryId}/products/${productId}/billing-cycles`,
    );

    console.log('getBillingCycles response for product:', productId, data.data);
    return data.data;
};

export const syncBillingCycles = (
    categoryId: number,
    productId: number,
    cycles: Array<{ days: number; is_enabled: boolean }>,
): Promise<void> => {
    console.log('syncBillingCycles called:', {
        categoryId,
        productId,
        cycles,
        url: `/api/application/billing/categories/${categoryId}/products/${productId}/billing-cycles/sync`,
    });

    return new Promise((resolve, reject) => {
        http.post(`/api/application/billing/categories/${categoryId}/products/${productId}/billing-cycles/sync`, {
            cycles,
        })
            .then(() => {
                console.log('syncBillingCycles success for product:', productId);
                resolve();
            })
            .catch(error => {
                console.error('syncBillingCycles error for product:', productId, error);
                reject(error);
            });
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
