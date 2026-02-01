export type OrderStatus = 'pending' | 'expired' | 'failed' | 'processed';

export type CouponType = 'percentage' | 'fixed';
export type CouponAllowedFor = 'both' | 'purchases' | 'renewals';

export interface ProductValues {
    categoryUuid: string;

    name: string;
    icon: string | undefined;
    price: number; // Single price for all billing cycles
    description: string;

    limits: {
        cpu: number;
        memory: number;
        disk: number;
        backup: number;
        database: number;
        allocation: number;
    };

    billingCycles?: number[]; // Array of billing cycle IDs
}

export interface CategoryValues {
    name: string;
    icon: string;
    description: string;
    visible: boolean;
    eggId: number;
    allowedEggs?: number[];
    allowEggChanges?: boolean;
    allowPlanChanges?: boolean;
}

export interface CouponValues {
    code: string;
    type: CouponType;
    value: number;
    maxUses: number | null;
    maxUsesPerUser: number | null;
    minOrderTotal: number | null;
    expiresAt: string | null;
    isActive: boolean;
    allowedFor: CouponAllowedFor;
}

export interface ProductFilters {
    id?: string;
    name?: string;
    price?: number;
}

export interface CategoryFilters {
    id?: number;
    name?: string;
}

export interface OrderFilters {
    id?: number;
    name?: string;
    description?: string;
    total?: number;
}

export interface BillingExceptionFilters {
    id?: number;
    title?: string;
}

export interface CouponFilters {
    code?: string;
    type?: CouponType;
    isActive?: boolean;
}
