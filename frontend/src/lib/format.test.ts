import { describe, expect, it } from 'vitest';
import { formatDuration, formatNumber } from './format';

// Intl output depends on the runtime's default locale, so compare against
// Intl itself rather than hard-coding "2,000,000".
const nf = (n: number, o: Intl.NumberFormatOptions = {}) => new Intl.NumberFormat(undefined, o).format(n);
const unit = (n: number, u: string, digits = 0) =>
    nf(n, { style: 'unit', unit: u, unitDisplay: 'short', maximumFractionDigits: digits });

describe('formatNumber', () => {
    it('groups digits', () => {
        expect(formatNumber(2_000_000)).toBe(nf(2_000_000));
        expect(formatNumber(2_000_000)).not.toBe('2000000');
    });

    it('treats non-numbers as zero', () => {
        expect(formatNumber(Number.NaN)).toBe(nf(0));
    });
});

describe('formatDuration', () => {
    it('uses ms under a second, seconds under ten minutes, then minutes and hours', () => {
        expect(formatDuration(850)).toBe(unit(850, 'millisecond'));
        expect(formatDuration(146_401)).toBe(unit(146.401, 'second', 1));
        expect(formatDuration(146_401)).toContain('146.4');
        expect(formatDuration(599_000)).toBe(unit(599, 'second', 1));
        expect(formatDuration(900_000)).toBe(unit(15, 'minute'));
        expect(formatDuration(3_900_000)).toBe(unit(1.0833, 'hour', 1));
    });

    it('never goes negative', () => {
        expect(formatDuration(-5)).toBe(unit(0, 'millisecond'));
    });
});
