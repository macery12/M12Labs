import { describe, expect, it } from 'vitest';
import { validateMultiplierValue } from '../BillingRulesContainer';

describe('validateMultiplierValue', () => {
    it('accepts values within range with decimals', () => {
        expect(validateMultiplierValue('0.1')).toBe(0.1);
        expect(validateMultiplierValue('1.25')).toBe(1.25);
        expect(validateMultiplierValue('10')).toBe(10);
    });

    it('rejects empty or non-numeric values', () => {
        expect(() => validateMultiplierValue('')).toThrowError();
        expect(() => validateMultiplierValue('abc')).toThrowError();
    });

    it('rejects values outside allowed bounds', () => {
        expect(() => validateMultiplierValue('0.05')).toThrowError();
        expect(() => validateMultiplierValue('10.5')).toThrowError();
    });
});
