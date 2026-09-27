// Heuristics for copy that was never meant to go live. The landing page shipped
// "PRICING HEADLINE", "THIS IS A TESTEMONIAL MESSAGE" and a store banner of
// "asds / aa / sdasd". The editors warn with these; they never block a save (D5),
// because an intentional all-caps line would trip the same rule.

const PHRASES = /\b(lorem ipsum|placeholder|todo|tbd|sample text|dummy text|change me|your text here)\b/i;
const THIS_IS_A = /^this is (a|an|the|my)\b/i;
// Keyboard mash on the home row: "asdf", "sdasd", "aa". A word only counts when
// it also has a pair real words don't ("sd", "jk", …) or is one letter repeated,
// so "add", "dash" and "salad" pass.
const HOME_ROW = /^[asdfghjkl]{2,}$/i;
const MASH_PAIR = /sd|ds|df|fd|fg|gf|jk|kj|kl|lk|hj|jh|sf|fs/i;
const isMash = (word: string) => HOME_ROW.test(word) && (MASH_PAIR.test(word) || /^(.)\1+$/i.test(word));

/** True when a piece of operator copy looks like filler rather than real text. */
export function looksLikePlaceholder(text: string | null | undefined): boolean {
    const t = (text ?? '').trim();
    if (!t) return false;
    if (PHRASES.test(t) || THIS_IS_A.test(t)) return true;
    if (t.split(/\s+/).every(w => isMash(w.replace(/[^a-z]/gi, '')) || !/[a-z]/i.test(w))) {
        return /[a-z]/i.test(t);
    }
    // Two or more all-caps words: "PRICING HEADLINE". Short acronyms ("FAQ",
    // "SFTP") and single shouted words stay allowed.
    const letters = t.replace(/[^a-z]/gi, '');
    const words = t.split(/\s+/).filter(w => /[a-z]/i.test(w));
    return words.length >= 2 && letters.length >= 8 && letters === letters.toUpperCase();
}

/** Names used by more than one item, compared case- and space-insensitively. */
export function duplicateNames(names: string[]): string[] {
    const seen = new Map<string, { name: string; count: number }>();
    for (const name of names) {
        const key = name.trim().toLowerCase().replace(/\s+/g, ' ');
        if (!key) continue;
        const entry = seen.get(key);
        if (entry) entry.count++;
        else seen.set(key, { name: name.trim(), count: 1 });
    }
    return [...seen.values()].filter(e => e.count > 1).map(e => e.name);
}

/**
 * Every string value inside an editor section's data, skipping keys that hold
 * links, images or identifiers rather than copy.
 */
export function collectCopy(value: unknown, skip: ReadonlySet<string> = COPY_SKIP_KEYS): string[] {
    if (typeof value === 'string') return [value];
    if (Array.isArray(value)) return value.flatMap(v => collectCopy(v, skip));
    if (value && typeof value === 'object') {
        return Object.entries(value).flatMap(([k, v]) => (skip.has(k) ? [] : collectCopy(v, skip)));
    }
    return [];
}

export const COPY_SKIP_KEYS: ReadonlySet<string> = new Set(['icon', 'href', 'backgroundImage', 'image', 'url']);
