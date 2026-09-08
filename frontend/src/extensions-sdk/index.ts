/*
 * Extension SDK — the supported surface for installed extension packages.
 *
 * Packages import from '@/extensions-sdk' and nothing else under '@/'. Panel
 * internals outside this module are unsupported and may change in any release;
 * the installer enforces that by rejecting other '@/' imports in shipped files.
 *
 * What belongs here: anything a package legitimately needs (authenticated HTTP
 * scoped to its own namespace, its server/permission context, namespaced query
 * keys and translations, failure isolation, approved UI primitives). What does
 * not: direct model access, arbitrary panel services, or anything that lets a
 * package address another extension's routes, keys or translations.
 */

export { SDK_VERSION, SDK_MAJOR } from './version';

export {
    createExtensionClient,
    createExtensionAdminClient,
    type ExtensionClient,
    type ExtensionListEnvelope,
    type ExtensionItemEnvelope,
} from './client';

export { extensionQueryKey, useExtensionQueryKey } from './query';

export { createTranslator } from './i18n';

export {
    useExtensionServerContext,
    useExtensionAdminContext,
    type ExtensionServerContext,
    type ExtensionAdminContext,
} from './context';

export { ExtensionErrorBoundary, ExtensionSuspense, withExtensionIsolation } from './ExtensionErrorBoundary';

// Approved UI primitives. Packages must use these (and the theme CSS variables)
// rather than importing panel components directly or shipping global CSS, so a
// package stays consistent across themes and cannot restyle the panel.
export { Button } from '@/components/ui/Button';
export { Input } from '@/components/ui/Input';
export { Textarea } from '@/components/ui/Textarea';
export { Select } from '@/components/ui/Select';
export { Switch } from '@/components/ui/Switch';
export { Modal } from '@/components/ui/Modal';
export { ConfirmDialog } from '@/components/ui/ConfirmDialog';
export { Panel } from '@/components/ui/Panel';
export { Spinner } from '@/components/ui/Spinner';
export { CopyField } from '@/components/ui/CopyField';
