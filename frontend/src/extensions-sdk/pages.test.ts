import { describe, expect, it } from 'vitest';
import { extensionFlagsSatisfied } from './pages';

describe('extensionFlagsSatisfied', () => {
    const flags = {
        assistant: {
            enabled: true,
            configured: false,
        },
    };

    it('allows declarations that require no package flags', () => {
        expect(extensionFlagsSatisfied(undefined, 'assistant', undefined)).toBe(true);
        expect(extensionFlagsSatisfied(undefined, 'assistant', [])).toBe(true);
    });

    it('requires every named server-evaluated flag', () => {
        expect(extensionFlagsSatisfied(flags, 'assistant', ['enabled'])).toBe(true);
        expect(extensionFlagsSatisfied(flags, 'assistant', ['enabled', 'configured'])).toBe(false);
    });

    it('fails closed for missing packages, values, and malformed generated data', () => {
        expect(extensionFlagsSatisfied(flags, 'missing', ['enabled'])).toBe(false);
        expect(extensionFlagsSatisfied(flags, 'assistant', ['unknown'])).toBe(false);
        expect(extensionFlagsSatisfied(flags, 'assistant', 'enabled' as unknown as string[])).toBe(false);
    });
});
