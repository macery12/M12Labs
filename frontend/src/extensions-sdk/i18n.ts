import { td } from '@/i18n';

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
 *   t('nav.server', 'Domains');   // -> ext.custom_domains.nav.server
 */
export function createTranslator(extensionId: string): (key: string, fallback: string) => string {
    const prefix = `ext.${extensionId}.`;

    return (key, fallback) => td(key.startsWith(prefix) ? key : `${prefix}${key}`, fallback);
}
