import { m } from '@/i18n/messages';
import { getLocale } from '@/paraglide/runtime';

// Formatting follows the panel's language, not the browser's. i18n/index.ts
// points Paraglide's getLocale at the resolved panel locale, so a Danish panel
// in an en-US browser prints 18.8.2026 rather than 8/18/2026 — and an English
// panel never picks up a Russian browser's "5,00 $".
export const uiLocale = (): string => getLocale();

// toFixed() in the panel locale: a fixed number of decimals, no grouping, so
// English output is unchanged ("2.0 GB") while Danish and Russian get "2,0 GB".
const fixed = (n: number, digits: number): string =>
    new Intl.NumberFormat(uiLocale(), { minimumFractionDigits: digits, maximumFractionDigits: digits, useGrouping: false }).format(n);

// Bytes -> human string (binary units, matching panel conventions).
export function formatBytes(bytes: number, decimals = 1): string {
    if (!bytes || bytes <= 0) return '0 MB';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${fixed(bytes / Math.pow(1024, i), i < 2 ? 0 : decimals)} ${units[i]}`;
}

// Server-list limits are in MiB (0 == unlimited).
export function formatMib(mib: number): string {
    if (mib === 0) return '∞';
    return mib >= 1024 ? `${fixed(mib / 1024, mib % 1024 === 0 ? 0 : 1)} GB` : `${mib} MB`;
}

export const mibToBytes = (mib: number): number => mib * 1024 * 1024;

// Money formatting for the billing surfaces. The panel's configured currency
// isn't exposed to the V2 frontend yet, so this defaults to USD; swap the code
// once the billing currency is surfaced through the config flags.
export function formatCurrency(amount: number, currency = 'USD'): string {
    return new Intl.NumberFormat(uiLocale(), { style: 'currency', currency }).format(amount || 0);
}

// A count with the panel locale's digit grouping: 2000000 → "2,000,000". Raw
// numbers past four digits are hard to read at a glance, and a limit field
// showing 2000000 gets misread by an order of magnitude.
export function formatNumber(n: number, maximumFractionDigits = 0): string {
    return new Intl.NumberFormat(uiLocale(), { maximumFractionDigits }).format(Number.isFinite(n) ? n : 0);
}

// CPU limits are a percentage of one core (200 = two cores), which players
// read as nonsense on a plan card. Plans show cores instead: 200 → "2 vCPU",
// 50 → "0.5 vCPU". 0 is unlimited. cpuPercentHint keeps the raw figure for a
// tooltip, for anyone comparing against another host that quotes percent.
export function formatVcpu(percent: number): string {
    if (!Number.isFinite(percent) || percent <= 0) return m['common.units.cpuUnlimited']();
    return m['common.units.vcpu']({ value: formatNumber(percent / 100, 2) });
}

export function cpuPercentHint(percent: number): string | undefined {
    return Number.isFinite(percent) && percent > 0 ? m['common.units.cpuPercent']({ percent: formatNumber(percent) }) : undefined;
}

// A measured duration in milliseconds: 850 → "850 ms", 146401 → "146.4 sec",
// 3_900_000 → "65 min". Latencies were printed as "146401ms". Seconds run up to
// ten minutes, because a slow request compares better as "146.4 sec" than as
// "2.4 min".
export function formatDuration(ms: number): string {
    const value = Number.isFinite(ms) && ms > 0 ? ms : 0;
    const unit = (n: number, u: 'millisecond' | 'second' | 'minute' | 'hour', digits = 0) =>
        new Intl.NumberFormat(uiLocale(), { style: 'unit', unit: u, unitDisplay: 'short', maximumFractionDigits: digits }).format(n);

    if (value < 1000) return unit(value, 'millisecond');
    if (value < 600_000) return unit(value / 1000, 'second', 1);
    if (value < 3_600_000) return unit(value / 60_000, 'minute');
    return unit(value / 3_600_000, 'hour', 1);
}

// Daemon uptime is milliseconds.
export function formatUptime(ms: number): string {
    if (!ms || ms <= 0) return '—';
    const s = Math.floor(ms / 1000);
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const min = Math.floor((s % 3600) / 60);
    const part = (n: number, unit: 'day' | 'hour' | 'minute') =>
        new Intl.NumberFormat(uiLocale(), { style: 'unit', unit, unitDisplay: 'narrow' }).format(n);
    if (d > 0) return `${part(d, 'day')} ${part(h, 'hour')}`;
    if (h > 0) return `${part(h, 'hour')} ${part(min, 'minute')}`;
    return part(min, 'minute');
}

// Calendar date / date-and-time / time of day in the panel's locale.
export function formatDate(input: string | number | Date, options?: Intl.DateTimeFormatOptions): string {
    return new Date(input).toLocaleDateString(uiLocale(), options);
}

export function formatDateTime(input: string | number | Date, options?: Intl.DateTimeFormatOptions): string {
    return new Date(input).toLocaleString(uiLocale(), options);
}

export function formatTime(input: string | number | Date, options?: Intl.DateTimeFormatOptions): string {
    return new Date(input).toLocaleTimeString(uiLocale(), options);
}

// Compact "time ago" without pulling a date library. English keeps the narrow
// "22h ago" form; other locales use the short style, because narrow Russian
// renders as a bare "-22 ч".
export function timeAgo(input: string | number | Date): string {
    const then = new Date(input).getTime();
    const diff = Math.max(0, Date.now() - then);
    const s = Math.floor(diff / 1000);
    if (s < 60) return m['common.time.justNow']();
    const locale = uiLocale();
    const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'always', style: locale === 'en' ? 'narrow' : 'short' });
    const min = Math.floor(s / 60);
    if (min < 60) return rtf.format(-min, 'minute');
    const h = Math.floor(min / 60);
    if (h < 24) return rtf.format(-h, 'hour');
    const d = Math.floor(h / 24);
    if (d < 30) return rtf.format(-d, 'day');
    return formatDate(then);
}
