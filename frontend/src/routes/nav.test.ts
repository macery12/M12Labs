import { describe, expect, it } from 'vitest';
import { Bot, Globe, Puzzle, Server, Sparkles } from 'lucide-react';
import { applyNavLayout, buildNav, flattenNav } from './nav';
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

describe('applyNavLayout', () => {
    const opts = { defaultCollapsed: ['operations'], unhideable: ['extensions'] };
    const groups = () => buildNav(routes, { flags, held: ['*'], basePath: '/admin' });

    it('keeps the registry order and marks default-folded groups when there is no layout', () => {
        const out = applyNavLayout(groups(), null, opts);

        expect(out.map(g => g.category)).toEqual([null, 'extensions', 'operations']);
        expect(out[2]?.defaultCollapsed).toBe(true);
        expect(out[1]?.defaultCollapsed).toBe(false);
    });

    it('reorders, renames, moves extension entries, hides, and sends unmentioned entries home', () => {
        const out = applyNavLayout(
            groups(),
            {
                groups: [
                    { key: 'custom-daily', label: 'Daily', collapsed: false, items: ['infrastructure', 'ext:ai', 'ext:gone'] },
                    { key: 'extensions', label: null, collapsed: true, items: ['extensions'] },
                ],
                hidden: ['ext:custom_domains'],
            },
            opts,
        );

        // Overview stays on top, outside the layout.
        expect(out[0]?.items.map(i => i.id)).toEqual(['overview']);
        expect(out[1]).toMatchObject({ category: 'custom-daily', label: 'Daily', defaultCollapsed: false });
        expect(out[1]?.items.map(i => i.id)).toEqual(['infrastructure', 'ext:ai']);
        expect(out[2]).toMatchObject({ category: 'extensions', label: undefined, defaultCollapsed: true });
        expect(out[2]?.items.map(i => i.id)).toEqual(['extensions']);
        // Operations lost its only entry to Daily, so it's dropped as empty.
        expect(out).toHaveLength(3);
    });

    it('never surfaces an entry the viewer cannot see, and ignores hiding the unhideable', () => {
        const visible = buildNav(routes, { flags, held: [], basePath: '/admin' });
        const out = applyNavLayout(
            visible,
            { groups: [{ key: 'custom-a', label: 'A', collapsed: false, items: ['infrastructure', 'extensions'] }], hidden: ['extensions'] },
            opts,
        );

        expect(out[1]?.items.map(i => i.id)).toEqual(['extensions']);
    });

    it('recreates a default group the layout dropped for an entry it never mentions', () => {
        const out = applyNavLayout(groups(), { groups: [{ key: 'extensions', label: null, collapsed: false, items: [] }], hidden: [] }, opts);

        expect(out.map(g => g.category)).toEqual([null, 'extensions', 'operations']);
        expect(out[2]?.defaultCollapsed).toBe(true);
    });
});
