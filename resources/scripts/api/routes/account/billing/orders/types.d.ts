export type OrderStatus = 'pending' | 'expired' | 'failed' | 'processed';
export type OrderType = 'new' | 'upg' | 'ren';
export type PaymentProcessor = 'stripe' | 'mollie' | 'paypal';

export interface OrderFilters {
    id?: number;
    name?: string;
    payment_processor?: PaymentProcessor;
}

export interface UpdateStripeIntent {
    id: number;
    node_id?: number;
    intent: string;
    vars?: { key: string; value: string }[];
    serverId?: number;
    renewal?: boolean;
    coupon_id?: number;
    egg_id?: number;
    name?: string;
}
