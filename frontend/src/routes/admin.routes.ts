import {
    LayoutDashboard,
    Settings,
    Activity,
    KeyRound,
    BookOpen,
    ShieldCheck,
    CreditCard,
    LifeBuoy,
    Boxes,
    Mail,
    Webhook,
    Puzzle,
    Palette,
    LayoutTemplate,
    Bell,
    Database,
    Server,
    Egg,
    ToggleRight,
    Link2,
    Users,
    UserCog,
    ListOrdered,
} from 'lucide-react';
import { lazy } from 'react';
import { route, type RouteDef } from './registry';
import { extensionAdminRoutes } from './extensionAdmin.routes';
import { NodesRedirect, ServersRedirect } from '@/pages/admin/infrastructure/InfraRedirect';
import {
    AccessIndexRedirect,
    ApiKeysAccessRedirect,
    PeopleAccessRedirect,
    RolesAccessRedirect,
    UsersAccessRedirect,
} from '@/pages/admin/access/AccessRedirect';

const SettingsSection = lazy(() => import('@/pages/admin/settings/SettingsSection'));
const InfrastructureSection = lazy(() => import('@/pages/admin/infrastructure/InfrastructureSection'));
const ThemeSection = lazy(() => import('@/pages/admin/theme/ThemeSection'));
const ExtensionsSection = lazy(() => import('@/pages/admin/extensions/ExtensionsSection'));
const BillingSection = lazy(() => import('@/pages/admin/billing/BillingSection'));
const LandingSection = lazy(() => import('@/pages/admin/landing/LandingSection'));
const EmailSection = lazy(() => import('@/pages/admin/email/EmailSection'));
const TicketsSection = lazy(() => import('@/pages/admin/tickets/TicketsSection'));
const UsersSection = lazy(() => import('@/pages/admin/users/UsersSection'));
const AccessProfilesSection = lazy(() => import('@/pages/admin/roles/RolesSection'));
const ApiKeysSection = lazy(() => import('@/pages/admin/api/ApiKeysSection'));
const MarketplaceSection = lazy(() => import('@/pages/admin/marketplace/MarketplaceSection'));
const AdminActivityPage = lazy(() => import('@/pages/admin/activity/AdminActivityPage'));
const NestsSection = lazy(() => import('@/pages/admin/nests/NestsSection'));
const ApiDocsPage = lazy(() => import('@/pages/admin/apidocs/ApiDocsPage'));
const AuthSection = lazy(() => import('@/pages/admin/auth/AuthSection'));
const WebhooksSection = lazy(() => import('@/pages/admin/webhooks/WebhooksSection'));
const AlertsSection = lazy(() => import('@/pages/admin/alerts/AlertsSection'));
const OverviewPage = lazy(() => import('@/pages/admin/overview/OverviewPage'));
const DatabasesSection = lazy(() => import('@/pages/admin/databases/DatabasesSection'));
const FeaturesSection = lazy(() => import('@/pages/admin/features/FeaturesSection'));
const LinksSection = lazy(() => import('@/pages/admin/links/LinksSection'));
const AdminIndexRedirect = lazy(() => import('@/pages/admin/overview/AdminIndexRedirect'));
const QueuesPage = lazy(() => import('@/pages/admin/queues/QueuesPage'));

// Admin area (/admin/*) — sidebar grouped by `category`, in registry order.
//
// Ordered for how the panel is used rather than by what kind of page each
// entry is: Overview, then Extensions (the panel's headline feature, which
// used to sit sixteenth, inside Modules), then the pages an admin opens daily,
// then setup, with the rarely visited System tools last and collapsed by
// default (AdminLayout). The command palette reads the same registry, so it
// follows this order too.
export const adminRoutes: RouteDef[] = [
    route('', { element: AdminIndexRedirect }),

    route('overview', { name: 'Overview', icon: LayoutDashboard, permission: 'overview.read', end: true, element: OverviewPage }),

    // Extensions: the management screen first, then one entry per installed
    // extension. Their static extensions/ext/<id>/* paths outrank the
    // extensions/* management splat whatever the registry order.
    route('extensions/*', { name: 'Extensions', icon: Puzzle, category: 'extensions', permission: 'extensions.read', condition: f => f.extensions.enabled, element: ExtensionsSection }),
    ...extensionAdminRoutes,

    route('infrastructure/*', { name: 'Infrastructure', icon: Server, category: 'operations', permission: ['nodes.read', 'servers.read'], element: InfrastructureSection }),
    route('access/users/*', { name: 'Users', icon: Users, category: 'operations', permission: 'users.read', element: UsersSection }),
    route('tickets/*', { name: 'Tickets', icon: LifeBuoy, category: 'operations', permission: 'tickets.read', condition: f => f.tickets.enabled, element: TicketsSection }),
    route('billing/*', { name: 'Billing', icon: CreditCard, category: 'operations', permission: 'billing.read', condition: f => f.billing.enabled, element: BillingSection }),

    // What customers see: the public landing page, the mod marketplace, the
    // banner alerts and the operator's links (no feature flag on Links — the
    // per-link `visible` column is the off switch, V1 parity).
    route('landing/*', { name: 'Landing Page', icon: LayoutTemplate, category: 'storefront', permission: 'settings.read', element: LandingSection }),
    route('marketplace/*', { name: 'Marketplace', icon: Boxes, category: 'storefront', permission: 'mods.read', condition: f => f.mods.enabled, element: MarketplaceSection }),
    route('alerts/*', { name: 'Alerts', icon: Bell, category: 'storefront', permission: 'alerts.read', element: AlertsSection }),
    route('links', { name: 'Links', icon: Link2, category: 'storefront', permission: 'links.read', element: LinksSection }),

    // Who may do what. Users moved up to Operations as a daily page; the paths
    // keep their access/ prefix so bookmarks and the redirects below still hold.
    route('access/profiles/*', { name: 'Access Profiles', icon: UserCog, category: 'access', permission: 'roles.read', element: AccessProfilesSection }),
    route('access/api-keys/*', { name: 'API Keys', icon: KeyRound, category: 'access', permission: 'api.read', element: ApiKeysSection }),
    route('auth/*', { name: 'Auth', icon: ShieldCheck, category: 'access', permission: 'auth.read', element: AuthSection }),
    // Bare /admin/access has no page of its own now; send it to the first
    // section the viewer may open.
    route('access', { element: AccessIndexRedirect }),

    route('settings/*', { name: 'Settings', icon: Settings, category: 'configuration', permission: 'settings.read', element: SettingsSection }),
    route('features', { name: 'Features', icon: ToggleRight, category: 'configuration', permission: 'settings.read', element: FeaturesSection }),
    route('theme', { name: 'Theme', icon: Palette, category: 'configuration', permission: 'theme.read', element: ThemeSection }),
    route('email/*', { name: 'Email', icon: Mail, category: 'configuration', permission: 'email.read', condition: f => !!f.email.module_enabled, element: EmailSection }),
    route('webhooks/*', { name: 'Webhooks', icon: Webhook, category: 'configuration', permission: 'webhooks.read', condition: f => f.webhooks.enabled, element: WebhooksSection }),
    route('nests/*', { name: 'Nests', icon: Egg, category: 'configuration', permission: 'nests.read', element: NestsSection }),
    route('databases/*', { name: 'Databases', icon: Database, category: 'configuration', permission: 'databases.read', element: DatabasesSection }),

    // Audit trail, background machinery and API reference: occasional visits.
    route('activity', { name: 'Activity', icon: Activity, category: 'system', permission: 'activity.read', element: AdminActivityPage }),
    route('queues', { name: 'Queues', icon: ListOrdered, category: 'system', permission: 'queues.read', end: true, element: QueuesPage }),
    route('developers/api-docs', { name: 'API Docs', icon: BookOpen, category: 'system', permission: 'api.read', element: ApiDocsPage }),

    // Legacy paths redirect into the merged Infrastructure section (hidden from nav).
    route('nodes/*', { element: NodesRedirect }),
    route('servers/*', { element: ServersRedirect }),
    // Compatibility redirects for bookmarks and integrations targeting the
    // previous standalone access-management pages, plus `access/people` from the
    // short-lived tabbed section.
    route('users/*', { element: UsersAccessRedirect }),
    route('roles/*', { element: RolesAccessRedirect }),
    route('api/*', { element: ApiKeysAccessRedirect }),
    route('access/people/*', { element: PeopleAccessRedirect }),
];
