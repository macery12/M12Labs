import { DiscountCode, Transformers } from '@definitions/admin';
import { DiscountCodeFilters, DiscountCodeValues } from './types';
import { createPaginatedHook, createContext } from '@/api';
import http from '@/api/http';

export const Context = createContext<DiscountCodeFilters>();

export const useGetDiscountCodes = createPaginatedHook<DiscountCode, DiscountCodeFilters>({
    url: '/api/application/billing/discount-codes',
    swrKey: 'discount-codes',
    context: Context,
    transformer: Transformers.toDiscountCode,
});

export const createDiscountCode = (values: DiscountCodeValues): Promise<DiscountCode> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/application/billing/discount-codes`, values)
            .then(({ data }) => resolve(Transformers.toDiscountCode(data)))
            .catch(reject);
    });
};

export const updateDiscountCode = (id: number, values: DiscountCodeValues): Promise<DiscountCode> => {
    return new Promise((resolve, reject) => {
        http.patch(`/api/application/billing/discount-codes/${id}`, values)
            .then(({ data }) => resolve(Transformers.toDiscountCode(data)))
            .catch(reject);
    });
};

export const deleteDiscountCode = (id: number): Promise<void> => {
    return new Promise((resolve, reject) => {
        http.delete(`/api/application/billing/discount-codes/${id}`)
            .then(() => resolve())
            .catch(reject);
    });
};
