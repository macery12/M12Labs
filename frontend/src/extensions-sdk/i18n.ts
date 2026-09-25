import { td, tdi } from '@/i18n';

/**
 * Translation helper bound to an extension's key namespace.
 *
 * Packages ship `messages/<locale>.json` fragments whose keys must all start
 * `ext.<id>.`; the panel merges them into its Paraglide compile input at build
 * time. Those keys do not exist in the panel's typed `m` surface at package
 * authoring time, so lookups go through the dynamic helper, and the required
 * English fallback covers any locale that has not translated a key yet.
 *
 *   const t = createTranslator('custom_domains');
 *   t('nav.server', 'Domains');                                  // static
 *   t('connectVia', 'Connect via {address}', { address: host }); // interpolated
 *
 * The fallback is interpolated too, so a locale missing the key reads correctly
 * instead of showing raw `{placeholders}`.
 */
export function createTranslator(
    extensionId: string,
): (key: string, fallback: string, inputs?: Record<string, unknown>) => string {
    const prefix = `ext.${extensionId}.`;

    return (key, fallback, inputs) => {
        const id = key.startsWith(prefix) ? key : `${prefix}${key}`;

        return inputs === undefined ? td(id, fallback) : tdi(id, fallback, inputs);
    };
}
