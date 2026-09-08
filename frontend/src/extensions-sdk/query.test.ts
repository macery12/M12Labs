import { describe, expect, it } from 'vitest';
import { extensionQueryKey } from './query';

describe('extensionQueryKey', () => {
    // Keying by id AND installed version means an update cannot serve the
    // previous version's cached response to new code.
    it('namespaces by extension id and version', () => {
        expect(extensionQueryKey('custom_domains', '3.0.0', 'records', { page: 2 })).toEqual([
            'ext',
            'custom_domains',
            '3.0.0',
            'records',
            { page: 2 },
        ]);
    });

    it('cannot collide across extensions or versions', () => {
        expect(extensionQueryKey('a', '1.0.0', 'x')).not.toEqual(extensionQueryKey('b', '1.0.0', 'x'));
        expect(extensionQueryKey('a', '1.0.0', 'x')).not.toEqual(extensionQueryKey('a', '1.0.1', 'x'));
    });
});
