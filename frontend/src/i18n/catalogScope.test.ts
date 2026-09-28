import { describe, expect, it, vi } from 'vitest';

const loads: string[] = [];

vi.mock('./generated/catalogLoaders', () => ({
    publicCatalogLoaders: { en: async () => (loads.push('public'), {}) },
    appCatalogLoaders: { en: async () => (loads.push('app'), { dashboard_title: () => 'Dashboard' }) },
    catalogLoaders: {
        en: async () => (loads.push('full'), { dashboard_title: () => 'Dashboard', admin_title: () => 'Admin' }),
    },
}));

// Signed-in players boot on the `app` tier; AdminLayout upgrades to `full`.
// Loading a tier the active catalog already covers must be free, and must
// never swap the admin catalog back out for the smaller one.
describe('catalog tiers', () => {
    it('upgrades app to full once, and never downgrades', async () => {
        const { catalogCovers, initializeMessages, m } = await import('./messages');
        const messages = m as unknown as Record<string, (() => string) | undefined>;

        await initializeMessages('en', 'app');
        expect(catalogCovers('en', 'app')).toBe(true);
        expect(catalogCovers('en', 'full')).toBe(false);
        expect(messages['admin.title']).toBeUndefined();

        await initializeMessages('en', 'full');
        expect(messages['admin.title']?.()).toBe('Admin');

        await initializeMessages('en', 'app');
        await initializeMessages('en', 'public');
        expect(loads).toEqual(['app', 'full']);
        expect(messages['dashboard.title']?.()).toBe('Dashboard');
    });
});
