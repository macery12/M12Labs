import { lazy } from 'react';
import * as Icon from '@heroicons/react/outline';
import { route, type RouteDefinition } from '@/routers/routes/utils';

const CredentialsContainer = lazy(() => import('@account/CredentialsContainer'));
const AccountOverviewContainer = lazy(() => import('@account/AccountOverviewContainer'));
const SecurityContainer = lazy(() => import('@account/SecurityContainer'));

const TicketContainer = lazy(() => import('@account/tickets/TicketContainer'));
const ViewTicketContainer = lazy(() => import('@account/tickets/view/ViewTicketContainer'));

const ProductsContainer = lazy(() => import('@account/billing/ProductsContainer'));
const OrderContainer = lazy(() => import('@account/billing/order/OrderContainer'));
const OrdersContainer = lazy(() => import('@account/billing/orders/OrdersContainer'));
const Processing = lazy(() => import('@account/billing/order/summary/Processing'));
const Success = lazy(() => import('@account/billing/order/summary/Success'));
const Cancel = lazy(() => import('@account/billing/order/summary/Cancel'));

const DonationContainer = lazy(() => import('@account/donations/DonationContainer'));
const DonationHistoryContainer = lazy(() => import('@account/donations/DonationHistoryContainer'));

const account: RouteDefinition[] = [
    /**
     * Account - General Routes
     */
    route('', AccountOverviewContainer, { name: 'Account', end: true, icon: Icon.UserIcon }),
    route('credentials', CredentialsContainer, { name: 'Credentials', icon: Icon.KeyIcon }),
    route('security', SecurityContainer, { name: 'Security', icon: Icon.ShieldCheckIcon }),

    /**
     * Account - Ticket Routes
     */
    route('tickets', TicketContainer, {
        name: 'Tickets',
        icon: Icon.TicketIcon,
        condition: flags => flags.tickets.enabled,
    }),
    route('tickets/:id', ViewTicketContainer, { condition: flags => flags.tickets.enabled }),

    /**
     * Account - Billing Routes
     */
    route('billing/order', ProductsContainer, {
        name: 'Billing',
        icon: Icon.CashIcon,
        condition: flags => flags.billing.enabled,
    }),
    route('billing/order/:id', OrderContainer),
    route('billing/orders', OrdersContainer, {
        name: 'Orders',
        icon: Icon.ClipboardListIcon,
        condition: flags => flags.billing.enabled,
    }),
    route('billing/processing', Processing),
    route('billing/success', Success),
    route('billing/cancel', Cancel),

    /**
     * Account - Donation Routes
     */
    route('donations', DonationContainer, {
        name: 'Donate',
        icon: Icon.HeartIcon,
        condition: flags => flags.billing.enabled && flags.billing.donations_enabled,
    }),
    route('donations/history', DonationHistoryContainer, {
        condition: flags => flags.billing.enabled && flags.billing.donations_enabled,
    }),
];

export default account;
