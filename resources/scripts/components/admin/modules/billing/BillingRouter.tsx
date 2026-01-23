import { useStoreState } from '@/state/hooks';
import { Route, Routes } from 'react-router-dom';
import { NotFound } from '@/elements/ScreenBlock';
import AdminContentBlock from '@/elements/AdminContentBlock';
import EnableBilling from '@admin/modules/billing/EnableBilling';
import FlashMessageRender from '@/elements/FlashMessageRender';
import ProductForm from '@admin/modules/billing/products/ProductForm';
import CategoryForm from '@admin/modules/billing/products/CategoryForm';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';
import OverviewContainer from '@/components/admin/modules/billing/overview/OverviewContainer';
import CategoryTable from '@admin/modules/billing/products/CategoryTable';
import OrdersContainer from '@admin/modules/billing/orders/OrdersContainer';
import ProductContainer from '@admin/modules/billing/products/ProductContainer';
import CategoryContainer from '@admin/modules/billing/products/CategoryContainer';
import CouponsContainer from '@admin/modules/billing/coupons/CouponsContainer';
import CouponForm from '@admin/modules/billing/coupons/CouponForm';
import DonationsContainer from '@admin/modules/billing/donations/DonationsContainer';
import {
    CalendarIcon,
    CogIcon,
    DesktopComputerIcon,
    ShoppingCartIcon,
    TicketIcon,
    ViewGridIcon,
    XCircleIcon,
    HeartIcon,
} from '@heroicons/react/outline';
import Unfinished from '@/elements/Unfinished';
import SettingsContainer from '@admin/modules/billing/SettingsContainer';
import BillingExceptionsContainer from './exceptions/BillingExceptionsContainer';
import RenewalDatesContainer from '@admin/modules/billing/RenewalDatesContainer';
import IntegrationsContainer from './integrations/IntegrationsContainer';
import { createIntegrationRegistry, getEnabledIntegrations } from './integrations/registry';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPuzzlePiece } from '@fortawesome/free-solid-svg-icons';

export default () => {
    const enabled = useStoreState(state => state.everest.data!.billing.enabled);
    const billingSettings = useStoreState(state => state.everest.data!.billing);

    if (!enabled) return <EnableBilling />;

    // Get enabled integrations to dynamically render tabs
    const integrations = createIntegrationRegistry(billingSettings);
    const enabledIntegrations = getEnabledIntegrations(integrations);

    return (
        <AdminContentBlock title={'Billing'}>
            <div className={'mb-8 flex w-full flex-row items-center'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Billing</h2>
                    <p className={'overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400'}>
                        Configure the billing settings for this panel.
                    </p>
                </div>
            </div>

            <Unfinished untested />

            <FlashMessageRender byKey={'admin:billing'} className={'mb-4'} />

            <SubNavigation>
                <SubNavigationLink to={'/admin/billing'} name={'Overview'} base>
                    <DesktopComputerIcon />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/billing/categories'} name={'Products'}>
                    <ViewGridIcon />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/billing/orders'} name={'Orders'}>
                    <ShoppingCartIcon />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/billing/donations'} name={'Donations'}>
                    <HeartIcon />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/billing/coupons'} name={'Coupons'}>
                    <TicketIcon />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/billing/exceptions'} name={'Exceptions'}>
                    <XCircleIcon />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/billing/renewal-dates'} name={'Renewal Dates'}>
                    <CalendarIcon />
                </SubNavigationLink>
                
                {/* Dynamically render tabs for enabled integrations */}
                {enabledIntegrations.map(integration => (
                    <SubNavigationLink
                        key={integration.id}
                        to={`/admin/billing/integrations/${integration.id}`}
                        name={integration.name}
                    >
                        <FontAwesomeIcon icon={integration.icon} />
                    </SubNavigationLink>
                ))}
                
                <SubNavigationLink to={'/admin/billing/integrations'} name={'Integrations'}>
                    <FontAwesomeIcon icon={faPuzzlePiece} />
                </SubNavigationLink>
                <SubNavigationLink to={'/admin/billing/settings'} name={'Settings'}>
                    <CogIcon />
                </SubNavigationLink>
            </SubNavigation>
            <Routes>
                <Route path={'/'} element={<OverviewContainer />} />

                <Route path={'/categories'} element={<CategoryTable />} />
                <Route path={'/categories/new'} element={<CategoryForm />} />
                <Route path={'/categories/:id'} element={<CategoryContainer />} />

                <Route path={'/categories/:id/products/new'} element={<ProductForm />} />
                <Route path={'/categories/:id/products/:productId'} element={<ProductContainer />} />

                <Route path={'/orders'} element={<OrdersContainer />} />

                <Route path={'/donations'} element={<DonationsContainer />} />

                <Route path={'/coupons'} element={<CouponsContainer />} />
                <Route path={'/coupons/new'} element={<CouponForm />} />
                <Route path={'/coupons/:id'} element={<CouponForm />} />

                <Route path={'/exceptions'} element={<BillingExceptionsContainer />} />

                <Route path={'/renewal-dates'} element={<RenewalDatesContainer />} />

                <Route path={'/integrations'} element={<IntegrationsContainer />} />
                
                {/* Dynamic routes for enabled integrations */}
                {enabledIntegrations.map(integration => (
                    <Route
                        key={integration.id}
                        path={`/integrations/${integration.id}`}
                        element={<integration.settingsComponent />}
                    />
                ))}

                <Route path={'/settings'} element={<SettingsContainer />} />

                <Route path={'/*'} element={<NotFound />} />
            </Routes>
        </AdminContentBlock>
    );
};
