import { describe, expect, it } from 'vitest';
import { adminExtensionBase, clientExtensionBase, joinExtensionPath } from './paths';

describe('extension route namespaces', () => {
    it('builds the loader-owned client namespace', () => {
        expect(clientExtensionBase('abc123', 'custom_domains')).toBe(
            '/api/client/servers/abc123/extensions/ext/custom_domains',
        );
    });

    it('builds the loader-owned admin namespace', () => {
        expect(adminExtensionBase('custom_domains')).toBe('/api/application/extensions/ext/custom_domains');
    });

    // A package must not be able to reach another extension's endpoints or a
    // core path by smuggling separators through an id or server id.
    it.each([
        ['../other', 'traversal'],
        ['a/b', 'slash'],
        ['a\\b', 'backslash'],
        ['', 'empty'],
    ])('rejects %s (%s) as an extension id', id => {
        expect(() => adminExtensionBase(id)).toThrow(/Invalid extension path segment/);
    });

    it('rejects an escaping server id', () => {
        expect(() => clientExtensionBase('../../application', 'demo')).toThrow(/Invalid extension path segment/);
    });

    it('percent-encodes segments rather than interpolating raw', () => {
        expect(clientExtensionBase('a b', 'demo')).toBe('/api/client/servers/a%20b/extensions/ext/demo');
    });

    describe('joinExtensionPath', () => {
        const base = '/api/application/extensions/ext/demo';

        it('returns the base unchanged for an empty or root path', () => {
            expect(joinExtensionPath(base, '')).toBe(base);
            expect(joinExtensionPath(base, '/')).toBe(base);
        });

        it('accepts paths with and without a leading slash', () => {
            expect(joinExtensionPath(base, 'records')).toBe(`${base}/records`);
            expect(joinExtensionPath(base, '/records')).toBe(`${base}/records`);
        });
    });
});
