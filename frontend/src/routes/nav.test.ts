import { describe, expect, it } from 'vitest';
import { Bot, Globe, Puzzle, Server, Sparkles } from 'lucide-react';
import { buildNav, flattenNav } from './nav';
import { route, type Flags, type RouteDef } from './registry';

const Page = () => null;
const flags = {} as Flags;

const ai = { id: 'ai', name: 'AI Assistant', icon: Bot };

const routes: RouteDef[] = [
    route('overview', { name: 'Overview', element: Page }),
    route('extensions/*', { name: 'Extensions', icon: Puzzle, category: 'extensions', element: Page }),
    route('extensions/ext/ai/assistant/*', { name: 'assistant', labelKey: 'ext.ai.nav.adminAssistant', icon: Sparkles, category: 'extensions', extension: ai, element: Page }),
    route('extensions/ext/ai/settings/*', { name: 'settings', labelKey: 'ext.ai.nav.admin', icon: Bot, category: 'extensions', extension: ai, element: Page }),
    route('extensions/ext/custom_domains/domains/*', {
        name: 'domains',
        labelKey: 'ext.custom_domains.nav.admin',
        icon: Globe,
        category: 'extensions',
        extension: { id: 'custom_domains', name: 'Custom Domains', icon: Globe },
        element: Page,
    }),
    route('infrastructure/*', { name: 'Infrastructure', icon: Server, category: 'operations', permission: 'nodes.read', element: Page }),
];

describe('buildNav', () => {
    it('folds an extension with several pages under one entry named after it', () => {
        const [top, extensions] = buildNav(routes, { flags, held: ['*'], basePath: '/admin' });

        expect(top?.category).toBeNull();
        expect(extensions?.category).toBe('extensions');

        const parent = extensions?.items[1];
        expect(parent?.key).toBe('ext:ai');
        expect(parent?.label).toBe('AI Assistant');
        expect(parent?.children?.map(c => c.to)).toEqual([
            '/admin/extensions/ext/ai/assistant',
            '/admin/extensions/ext/ai/settings',
        ]);
    });

    it('links a single-page extension straight to its page under the extension name', () => {
        const extensions = buildNav(routes, { flags, held: ['*'], basePath: '/admin' })[1];
        const domains = extensions?.items[2];

        expect(domains?.children).toBeUndefined();
        expect(domains?.to).toBe('/admin/extensions/ext/custom_domains/domains');
        expect(domains?.key).toBe('/admin/extensions/ext/custom_domains/domains');
        expect(domains?.label).toBe('Custom Domains');
    });

    it('falls back to the first page label when the page manifest predates names', () => {
        const unnamed = routes.map(r => (r.extension?.id === 'ai' ? { ...r, extension: { id: 'ai', icon: Bot } } : r));
        const parent = buildNav(unnamed, { flags, held: ['*'], basePath: '/admin' })[1]?.items[1];

        expect(parent?.label).toBeUndefined();
        expect(parent?.labelKey).toBe('ext.ai.nav.adminAssistant');
    });

    it('gates each page before grouping, so a parent never holds a page the viewer cannot open', () => {
        const gated = routes.map(r => (r.path.startsWith('extensions/ext/ai/settings') ? { ...r, permission: 'ext.ai.admin.write' } : r));
        const extensions = buildNav(gated, { flags, held: ['nodes.read'], basePath: '/admin' })[1];

        // One visible AI page left: it collapses to a direct link.
        const aiEntry = extensions?.items[1];
        expect(aiEntry?.children).toBeUndefined();
        expect(aiEntry?.to).toBe('/admin/extensions/ext/ai/assistant');
        expect(aiEntry?.label).toBe('AI Assistant');
    });
});

describe('flattenNav', () => {
    it('replaces parents with their pages and keeps the parent for context', () => {
        const flat = flattenNav(buildNav(routes, { flags, held: ['*'], basePath: '/admin' }));

        expect(flat.map(e => e.item.to)).toEqual([
            '/admin/overview',
            '/admin/extensions',
            '/admin/extensions/ext/ai/assistant',
            '/admin/extensions/ext/ai/settings',
            '/admin/extensions/ext/custom_domains/domains',
            '/admin/infrastructure',
        ]);
        expect(flat[3]?.parent?.key).toBe('ext:ai');
        expect(flat[4]?.parent).toBeNull();
    });
});
