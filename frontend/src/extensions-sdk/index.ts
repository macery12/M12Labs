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

export {
    createExtensionStream,
    type ExtensionStreamFrame,
    type ExtensionStreamOptions,
    type ExtensionStreamInfo,
} from './stream';

export {
    useExtensionStream,
    type ExtensionStreamController,
    type ExtensionStreamStatus,
    type StartExtensionStreamOptions,
} from './useExtensionStream';

export { extensionQueryKey, useExtensionQueryKey } from './query';

export {
    useExtensionFlag,
    useExtensionFlags,
    refreshExtensionFlags,
    type ExtensionFrontendState,
} from './flags';

export { createTranslator } from './i18n';

export {
    useExtensionServerContext,
    useExtensionAdminContext,
    type ExtensionServerContext,
    type ExtensionAdminContext,
} from './context';

export { ExtensionErrorBoundary, ExtensionSuspense, withExtensionIsolation } from './ExtensionErrorBoundary';

export {
    EXTENSION_SLOT_NAMES,
    type ExtensionSlotName,
    type ExtensionSlotProps,
} from './slots';

export { notify, extensionErrorMessage, type NotifyTone } from './notify';

// Approved UI primitives. Packages must use these (and the theme CSS variables)
// rather than importing panel components directly or shipping global CSS, so a
// package stays consistent across themes and cannot restyle the panel.
export { Button } from '@/components/ui/Button';
export { Input, Field } from '@/components/ui/Input';
export { Textarea } from '@/components/ui/Textarea';
export { Select } from '@/components/ui/Select';
export { Switch } from '@/components/ui/Switch';
export { Modal } from '@/components/ui/Modal';
export { ConfirmDialog } from '@/components/ui/ConfirmDialog';
export { Panel } from '@/components/ui/Panel';
export { Spinner } from '@/components/ui/Spinner';
export { CopyField } from '@/components/ui/CopyField';
export { HelpButton, HelpSteps } from '@/components/ui/HelpButton';

export { Markdown, type MarkdownProps } from './Markdown';
export {
    DiffView,
    type DiffViewProps,
    type DiffRow,
    type DiffGap,
} from './DiffView';
export {
    DataTable,
    type DataTableProps,
    type DataTableColumn,
    type DataTablePagination,
} from './DataTable';
export { SliderField, type SliderFieldProps } from './SliderField';
export {
    SectionNavigation,
    TabNavigation,
    type SectionNavigationProps,
    type SectionNavigationGroup,
    type SectionNavigationItem,
    type TabNavigationProps,
} from './Navigation';

// Tailwind class composition. Extension pages are inside the panel's Vite build
// and its Tailwind source globs cover them, so they style with the same
// utilities and theme variables core does. Handing them the panel's own merge
// helper stops each package bundling its own clsx/tailwind-merge copy.
export { cn } from '@/lib/cn';
