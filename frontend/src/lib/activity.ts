import { td } from '@/i18n/messages';

export interface DescribableActivity {
    event: string;
    description?: string | null;
    properties?: Record<string, unknown>;
}

/**
 * One line of human text for an activity log entry, shared by every feed
 * (dashboard, account, server, admin, admin overview). Five pages used to
 * title-case the raw event name themselves, so a sign-in read "Success" and an
 * extension key change read "Extensions Secret Update" with no hint of which
 * extension.
 *
 * Order: the catalog's label for the event plus the thing it acted on
 * ("Updated an extension API key · ai"), then the backend's description, then
 * a readable form of the event name. The label wins over the description
 * because descriptions are English-only and often vaguer ("A user was
 * updated"). Events without a label, such as ones an extension logs, fall
 * through to their description.
 */
export function describeActivity(entry: DescribableActivity): string {
    const label = td(activityMessageId(entry.event), '');
    if (label) {
        const subject = activitySubject(entry.properties);
        return subject ? `${label} · ${subject}` : label;
    }

    return entry.description || humanizeEvent(entry.event);
}

/** The label alone, for filter lists: "Opened a file", "AI assist escalate". */
export function activityEventLabel(event: string): string {
    return td(activityMessageId(event), '') || humanizeEvent(event);
}

/**
 * Merges runs of adjacent entries that share a key into one row with a count.
 * Opening the same file three times filled a page with identical "Opened a
 * file" rows. A null key never merges (edits with a diff, console commands).
 */
export function collapseRepeats<T>(items: T[], key: (item: T) => string | null): { entry: T; count: number }[] {
    const out: { entry: T; count: number; key: string | null }[] = [];
    for (const item of items) {
        const k = key(item);
        const last = out[out.length - 1];
        if (k !== null && last && last.key === k) last.count++;
        else out.push({ entry: item, count: 1, key: k });
    }
    return out.map(({ entry, count }) => ({ entry, count }));
}

/**
 * Like collapseRepeats, but an entry also joins an earlier row with the same
 * key when it happened within `windowMs` of that row's newest entry, even with
 * other entries in between. For short newest-first summaries: an admin adding
 * and editing five links interleaves "created" and "updated", which adjacent
 * merging leaves as five rows. The row keeps its newest entry and position.
 */
export function groupRecentRepeats<T>(
    items: T[],
    key: (item: T) => string | null,
    time: (item: T) => number,
    windowMs: number,
): { entry: T; count: number }[] {
    const out: { entry: T; count: number; key: string | null }[] = [];
    for (const item of items) {
        const k = key(item);
        const row = k === null ? undefined : out.find(r => r.key === k && Math.abs(time(r.entry) - time(item)) <= windowMs);
        if (row) row.count++;
        else out.push({ entry: item, count: 1, key: k });
    }
    return out.map(({ entry, count }) => ({ entry, count }));
}

/** 'admin:api-keys:create' → 'activity.event.admin.api_keys.create' */
export function activityMessageId(event: string): string {
    return `activity.event.${event.replace(/:/g, '.').replace(/-/g, '_')}`;
}

// Properties that name what an event acted on, most specific first. Logged
// models (user, server, node…) arrive whole, so their name is read from inside.
const SUBJECT_KEYS = [
    'extension_id',
    'name',
    'file',
    'identifier',
    'fingerprint',
    'email',
    'provider',
    'command',
    'allocation',
    'variable',
    'directory',
];
const SUBJECT_MODELS = ['user', 'server', 'node', 'egg', 'nest', 'product', 'category', 'coupon', 'alert', 'link', 'host', 'preset'];
const SUBJECT_MODEL_FIELDS = ['name', 'username', 'code', 'title'];

export function activitySubject(properties: Record<string, unknown> | undefined): string | null {
    if (!properties) return null;

    for (const key of SUBJECT_KEYS) {
        const value = subjectText(properties[key]);
        if (value) return value;
    }

    for (const key of SUBJECT_MODELS) {
        const model = properties[key];
        if (!model || typeof model !== 'object') continue;
        for (const field of SUBJECT_MODEL_FIELDS) {
            const value = subjectText((model as Record<string, unknown>)[field]);
            if (value) return value;
        }
    }

    return null;
}

function subjectText(value: unknown): string | null {
    if (typeof value !== 'string') return null;
    const text = value.trim();
    // '[hidden]' / '[REDACTED]' are the sanitizer's, not a name.
    if (!text || text.startsWith('[') || text.length > 80) return null;
    return text;
}

const SCOPES = new Set(['admin', 'auth', 'billing', 'event', 'ext', 'server', 'user']);
const ACRONYMS: Record<string, string> = { ai: 'AI', api: 'API', ip: 'IP', sftp: 'SFTP', sso: 'SSO', ssh: 'SSH' };

/** 'server:ai.assist.escalate' → 'AI assist escalate' */
export function humanizeEvent(event: string): string {
    const [scope = '', ...tail] = event.split(':');
    const rest = tail.length > 0 && SCOPES.has(scope) ? tail.join(' ') : event;
    const sentence = rest
        .split(/[:._\-\s]+/)
        .filter(Boolean)
        .map(word => ACRONYMS[word.toLowerCase()] ?? word.toLowerCase())
        .join(' ');
    return sentence ? sentence.charAt(0).toUpperCase() + sentence.slice(1) : event;
}
