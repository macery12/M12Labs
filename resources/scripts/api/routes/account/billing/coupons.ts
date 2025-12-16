import http from '@/api/http';

export interface ValidateCouponRequest {
    code: string;
    subtotal: number;
}

export interface ValidateCouponResponse {
    valid: boolean;
    coupon: {
        id: number;
        code: string;
        type: 'percentage' | 'fixed';
        value: number;
    };
    subtotal: number;
    discount: number;
    total: number;
}

export const validateCoupon = async (code: string, subtotal: number): Promise<ValidateCouponResponse> => {
    const { data } = await http.post('/api/client/billing/coupons/validate', {
        code,
        subtotal,
    });

    return data;
};
