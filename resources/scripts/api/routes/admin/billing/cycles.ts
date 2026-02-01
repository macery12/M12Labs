import http from '@/api/http';
import { BillingCycle } from '@/api/definitions/admin/models';

export interface BillingCycleValues {
    name: string;
    durationDays: number;
    sortOrder?: number;
    isActive?: boolean;
}

export const getBillingCycles = async (): Promise<{ data: BillingCycle[] }> => {
    const { data } = await http.get('/api/application/billing/cycles');
    return data;
};

export const getBillingCycle = async (id: number): Promise<{ data: BillingCycle }> => {
    const { data } = await http.get(`/api/application/billing/cycles/${id}`);
    return data;
};

export const createBillingCycle = async (values: BillingCycleValues): Promise<{ data: BillingCycle }> => {
    const { data } = await http.post('/api/application/billing/cycles', values);
    return data;
};

export const updateBillingCycle = async (
    id: number,
    values: Partial<BillingCycleValues>
): Promise<{ data: BillingCycle }> => {
    const { data } = await http.patch(`/api/application/billing/cycles/${id}`, values);
    return data;
};

export const deleteBillingCycle = async (id: number): Promise<void> => {
    await http.delete(`/api/application/billing/cycles/${id}`);
};
