import { Routes, Route } from 'react-router-dom';
import BillingOverviewPage from './BillingOverviewPage';
import ProductsPage from './products/ProductsPage';
import ProductEditorPage from './products/ProductEditorPage';
import CategoryDetailPage from './products/CategoryDetailPage';
import { BillingNav } from './BillingNav';
import { ComingSoon } from './ComingSoon';

// Mounted at the admin `billing/*` splat route. Owns the billing overview, the
// category-grouped product catalog, and the product editor. The secondary
// navigation lives HERE (an in-page rail), not on the main admin sidebar.
// The product editor is a focused full-page route, so it renders without the rail.
export default function BillingSection() {
    return (
        <Routes>
            <Route path="products/categories/new" element={<CategoryDetailPage />} />
            <Route path="products/categories/:categoryId" element={<CategoryDetailPage />} />
            <Route path="products/new" element={<ProductEditorPage />} />
            <Route path="products/:productId" element={<ProductEditorPage />} />
            <Route
                path="*"
                element={
                    <div className="flex flex-col gap-6 lg:flex-row lg:gap-8">
                        <BillingNav />
                        <div className="min-w-0 flex-1">
                            <Routes>
                                <Route index element={<BillingOverviewPage />} />
                                <Route path="products" element={<ProductsPage />} />
                                <Route path="orders" element={<ComingSoon titleKey="billing.nav.orders" />} />
                                <Route path="invoices" element={<ComingSoon titleKey="billing.nav.invoices" />} />
                                <Route path="coupons" element={<ComingSoon titleKey="billing.nav.coupons" />} />
                                <Route path="exceptions" element={<ComingSoon titleKey="billing.nav.exceptions" />} />
                                <Route path="settings" element={<ComingSoon titleKey="billing.nav.settings" />} />
                                <Route path="invoice-settings" element={<ComingSoon titleKey="billing.nav.invoiceSettings" />} />
                            </Routes>
                        </div>
                    </div>
                }
            />
        </Routes>
    );
}
