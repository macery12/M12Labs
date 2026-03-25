import http from '@/api/http';
import { DiscountCode, Transformers } from '@definitions/account/billing';

export const validateDiscountCode = (discount_code: string): Promise<DiscountCode> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/billing/discount-codes`, { discount_code })
            .then(({ data }) => resolve(Transformers.toDiscountCode(data)))
            .catch(reject);
    });
};
