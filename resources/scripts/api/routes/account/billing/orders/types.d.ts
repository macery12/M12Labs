export type OrderStatus = 'pending' | 'expired' | 'failed' | 'processed';
export type OrderType = 'new' | 'upg' | 'ren';

export interface OrderFilters {
    id?: number;
    name?: string;
}
