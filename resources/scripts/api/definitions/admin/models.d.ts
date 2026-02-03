import { ModelWithRelationships, Model, UUID } from '@definitions';
import { Server } from '@/api/routes/admin/server';
import { OrderType } from '@/api/routes/account/billing/orders/types';

type BillingExceptionType = 'payment' | 'deployment' | 'storefront' | 'webhook' | 'refund' | 'validation';
type OrderStatus = 'pending' | 'expired' | 'failed' | 'processed';

interface User extends ModelWithRelationships {
    id: number;
    uuid: UUID;
    externalId: string;
    username: string;
    email: string;
    language: string;
    admin_role_id: number | null;
    roleName: string;
    isRootAdmin: boolean;
    isUsingTwoFactor: boolean;
    avatarUrl: string;
    state: string;
    createdAt: Date;
    updatedAt: Date;
    relationships: {
        role: UserRole | null;
        // TODO: just use an API call, this is probably a bad idea for performance.
        servers?: Server[];
    };
}

interface UserRole extends ModelWithRelationships {
    id: number;
    name: string;
    description: string;
    color?: string | null;
    permissions: string[];
}

interface ApiKeyPermission extends Model {
    r_allocations: string;
    r_database_hosts: string;
    r_eggs: string;
    r_locations: string;
    r_nests: string;
    r_nodes: string;
    r_server_databases: string;
    r_servers: string;
    r_users: string;
}

interface ApiKey extends Model {
    id?: number;
    identifier: string;
    description: string;
    allowed_ips: string[];
    created_at: Date | null;
    last_used_at: Date | null;
}

interface BillingException extends Model {
    id: number;
    uuid: string;
    exception_type: BillingExceptionType;
    order_id?: number;
    title: string;
    description: string;
    created_at: Date;
    updated_at?: Date | null;
}

interface Ticket extends Model {
    id: number;
    title: string;
    user: User;
    assigned_to?: User | undefined;
    status: TicketStatus;
    created_at: Date;
    updated_at?: Date | null;
    relationships: {
        messages?: TicketMessage[];
    };
}

interface TicketMessage extends Model {
    id: number;
    message: string;
    author: User;
    created_at: Date;
    updated_at?: Date | null;
}

interface BillingAnalytics extends Model {
    orders: Order[];
    products: Product[];
    categories: Category[];
    donations?: Donation[];
}

interface Donation extends Model {
    id: number;
    user_id: number;
    payment_intent_id: string;
    amount: number;
    currency: string;
    status: 'pending' | 'completed' | 'failed';
    message?: string;
    created_at: Date;
    updated_at?: Date | null;
}

interface Order extends Model {
    id: number;
    name: string;
    user_id: number;
    description: string;
    total: number;
    status: OrderStatus;
    product_id: number;
    type: OrderType;
    threat_index: number;
    created_at: Date;
    updated_at?: Date | null;
}

interface Product extends Model {
    id: number;
    uuid: string;
    categoryUuid: number;

    name: string;
    icon?: string;
    price: number;
    basePrice?: number | null;
    description: string;

    limits: {
        cpu: number;
        memory: number;
        disk: number;
        backup: number;
        database: number;
        allocation: number;
    };

    createdAt: Date;
    updatedAt?: Date | null;

    relationships: {
        category?: Category;
    };
}

interface BillingCycle extends Model {
    id: number;
    productId: number;
    days: number;
    isEnabled: boolean;
    createdAt: Date;
    updatedAt?: Date | null;
}

interface BillingCycleWithPrice {
    id?: number;
    days: number;
    price: number;
    multiplier: number;
    discountPercent: number;
    isDefault: boolean;
    isEnabled?: boolean;
}

interface Category extends Model {
    id: number;
    uuid: string;
    name: string;
    icon: string;
    description: string;
    visible: boolean;
    nestId: number;
    eggId: number;
    allowedEggs: number[];
    allowEggChanges: boolean;
    allowPlanChanges: boolean;

    createdAt: Date;
    updatedAt?: Date | null;

    relationships: {
        products?: Product[];
    };
}

interface Coupon extends Model {
    id: number;
    code: string;
    type: 'percentage' | 'fixed';
    value: number;
    maxUses: number | null;
    maxUsesPerUser: number | null;
    minOrderTotal: number | null;
    expiresAt: Date | null;
    isActive: boolean;
    allowedFor: 'both' | 'purchases' | 'renewals';
    usageCount: number;
    createdAt: Date;
    updatedAt?: Date | null;
}

interface AdminRolePermission extends Model {
    key: string;
    description: string;
}

interface ServerPreset extends Model {
    id: number;
    name: string;
    description: string;

    cpu: number;
    memory: number;
    disk: number;
    nest_id?: number;
    egg_id?: number;

    created_at: Date;
    updated_at?: Date | null;
}
