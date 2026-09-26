import { describe, expect, it, vi } from 'vitest';

// The compiled catalog is a Vite virtual module, so tests see message ids and
// their inputs instead of the English text.
vi.mock('@/i18n/messages', () => ({
    m: new Proxy({}, { get: (_t, id: string) => (inputs?: object) => (inputs ? `${id} ${JSON.stringify(inputs)}` : id) }),
}));
import { cpuPercentHint, formatDuration, formatNumber, formatVcpu } from './format';

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

describe('formatVcpu', () => {
    it('shows cores, not percent', () => {
        const vcpu = (value: string) => `common.units.vcpu ${JSON.stringify({ value })}`;
        expect(formatVcpu(200)).toBe(vcpu(nf(2)));
        expect(formatVcpu(50)).toBe(vcpu(nf(0.5, { maximumFractionDigits: 2 })));
        expect(formatVcpu(125)).toBe(vcpu(nf(1.25, { maximumFractionDigits: 2 })));
    });

    it('reads 0 as unlimited, with no percent hint', () => {
        expect(formatVcpu(0)).toBe('common.units.cpuUnlimited');
        expect(cpuPercentHint(0)).toBeUndefined();
    });

    it('keeps the raw percent for the tooltip', () => {
        expect(cpuPercentHint(200)).toBe(`common.units.cpuPercent ${JSON.stringify({ percent: nf(200) })}`);
    });
});
