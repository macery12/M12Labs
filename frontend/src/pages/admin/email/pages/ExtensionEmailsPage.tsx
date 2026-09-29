import { m, td } from '@/i18n/messages';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Eye, Pencil, ScrollText } from 'lucide-react';
import { Switch } from '@/components/ui/Switch';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { FullPageSpinner } from '@/components/ui/Spinner';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import {
    getExtensionEmails,
    toggleExtensionEmail,
    updateExtensionEmailLimit,
    extensionEmailTemplatePath,
    type ExtensionEmails,
    type ExtensionEmailType,
    type ExtensionEmailsResponse,
} from '@/api/email';
import { SettingsCard, TonePill } from '../parts';
import { TemplateEditorDialog } from './TemplateEditorDialog';

export const EXTENSION_EMAILS_KEY = ['admin', 'email', 'extensions'] as const;

interface Editing {
    extension: ExtensionEmails;
    type: ExtensionEmailType;
    view: 'split' | 'preview';
}

// Mail sent by installed extensions, kept apart from the panel's own: grouped
// by extension, with a switch and an editable template per type, and the
// extension's hourly ceiling. The subject is the extension's and not editable,
// the same as a built-in email's.
export default function ExtensionEmailsPage() {
    const qc = useQueryClient();
    const push = useFlashes(s => s.push);
    const [editing, setEditing] = useState<Editing | null>(null);

    const query = useQuery({ queryKey: EXTENSION_EMAILS_KEY, queryFn: getExtensionEmails });

    const toggle = useMutation({
        mutationFn: ({ extension, type, enabled }: { extension: string; type: string; enabled: boolean }) =>
            toggleExtensionEmail(extension, type, enabled),
        onMutate: async ({ extension, type, enabled }) => {
            await qc.cancelQueries({ queryKey: EXTENSION_EMAILS_KEY });
            const prev = qc.getQueryData<ExtensionEmailsResponse>(EXTENSION_EMAILS_KEY);
            if (prev) {
                qc.setQueryData<ExtensionEmailsResponse>(EXTENSION_EMAILS_KEY, {
                    ...prev,
                    extensions: prev.extensions.map(e =>
                        e.id !== extension
                            ? e
                            : { ...e, types: e.types.map(t => (t.type === type ? { ...t, enabled } : t)) },
                    ),
                });
            }
            return { prev };
        },
        onError: (err, _vars, ctx) => {
            if (ctx?.prev) qc.setQueryData(EXTENSION_EMAILS_KEY, ctx.prev);
            push({ type: 'error', message: firstError(err) ?? m['admin.email.notifications.saveError']() });
        },
    });

    if (query.isLoading || !query.data) return <FullPageSpinner />;

    const { extensions } = query.data;

    return (
        <div className="flex flex-col gap-5">
            <div>
                <h2 className="text-lg font-semibold text-[var(--color-ink)]">{m['admin.email.extensions.title']()}</h2>
                <p className="mt-1 text-sm text-[var(--color-ink-muted)]">{m['admin.email.extensions.desc']()}</p>
            </div>

            {extensions.length === 0 ? (
                <div className="flex flex-col items-center justify-center gap-2 rounded-[var(--radius-card)] border border-dashed border-[var(--color-border-strong)] bg-[var(--color-surface)]/40 px-6 py-16 text-center">
                    <p className="text-sm font-medium text-[var(--color-ink)]">{m['admin.email.extensions.emptyTitle']()}</p>
                    <p className="max-w-md text-xs text-[var(--color-ink-muted)]">{m['admin.email.extensions.emptyBody']()}</p>
                </div>
            ) : (
                extensions.map(extension => (
                    <ExtensionCard
                        key={extension.id}
                        extension={extension}
                        onToggle={(type, enabled) => toggle.mutate({ extension: extension.id, type, enabled })}
                        onEdit={(type, view) => setEditing({ extension, type, view })}
                    />
                ))
            )}

            {editing && (
                <TemplateEditorDialog
                    template={{
                        key: editing.type.key,
                        label: td(editing.type.label_key, editing.type.type),
                        category: editing.extension.name,
                        variables: editing.type.variables,
                        is_customized: editing.type.is_customized,
                    }}
                    endpoint={extensionEmailTemplatePath(editing.extension.id, editing.type.type)}
                    listKey={EXTENSION_EMAILS_KEY}
                    initialView={editing.view}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}

function ExtensionCard({
    extension,
    onToggle,
    onEdit,
}: {
    extension: ExtensionEmails;
    onToggle: (type: string, enabled: boolean) => void;
    onEdit: (type: ExtensionEmailType, view: 'split' | 'preview') => void;
}) {
    const navigate = useNavigate();

    return (
        <SettingsCard
            title={extension.name}
            description={m['admin.email.extensions.sentThisHour']({
                used: extension.sent_this_hour,
                limit: extension.hourly_limit,
            })}
            right={
                <div className="flex items-center gap-2">
                    {!extension.active && (
                        <span title={m['admin.email.extensions.inactiveHint']()}>
                            <TonePill tone="neutral">{m['ui.states.inactive']()}</TonePill>
                        </span>
                    )}
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => navigate(`/admin/email/activity?extension=${encodeURIComponent(extension.id)}`)}
                    >
                        <ScrollText className="h-4 w-4" />
                        {m['admin.email.extensions.viewLog']()}
                    </Button>
                </div>
            }
        >
            <ul className="flex flex-col divide-y divide-[var(--color-border)]">
                {extension.types.map(type => (
                    <TypeRow
                        key={type.type}
                        type={type}
                        onToggle={enabled => onToggle(type.type, enabled)}
                        onEdit={view => onEdit(type, view)}
                    />
                ))}
            </ul>
            {/* Keyed on the saved value, so a refetch resets the field. */}
            <LimitForm key={extension.hourly_limit} extension={extension} />
        </SettingsCard>
    );
}

function TypeRow({
    type,
    onToggle,
    onEdit,
}: {
    type: ExtensionEmailType;
    onToggle: (enabled: boolean) => void;
    onEdit: (view: 'split' | 'preview') => void;
}) {
    const label = td(type.label_key, type.type);
    const description = type.description_key ? td(type.description_key, '') : '';

    return (
        <li className="flex flex-col gap-3 py-3 first:pt-0 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <span
                        className="text-sm font-medium text-[var(--color-ink)]"
                        title={m['admin.email.notifications.templateKey']({ key: type.key })}
                    >
                        {label}
                    </span>
                    {type.is_customized && <TonePill tone="success">{m['admin.email.templates.customized']()}</TonePill>}
                </div>
                {description && <p className="mt-0.5 text-xs text-[var(--color-ink-muted)]">{description}</p>}
                <p className="mt-1 truncate font-mono text-[11px] text-[var(--color-ink-faint)]" title={type.subject}>
                    {m['admin.email.extensions.subject']({ subject: type.subject })}
                </p>
            </div>
            <div className="flex shrink-0 items-center gap-3">
                <button
                    type="button"
                    onClick={() => onEdit('preview')}
                    className="inline-flex items-center gap-1 text-xs font-medium text-[var(--color-ink-muted)] hover:text-[var(--brand)]"
                >
                    <Eye className="h-3.5 w-3.5" />
                    {m['admin.email.templates.preview']()}
                </button>
                <button
                    type="button"
                    onClick={() => onEdit('split')}
                    className="inline-flex items-center gap-1 text-xs font-medium text-[var(--color-ink-muted)] hover:text-[var(--brand)]"
                >
                    <Pencil className="h-3.5 w-3.5" />
                    {m['common.actions.edit']()}
                </button>
                <Switch checked={type.enabled} onChange={onToggle} label={label} />
            </div>
        </li>
    );
}

// The operator's ceiling, not the extension's: a package stuck in a loop stops
// here instead of spending the provider allowance.
function LimitForm({ extension }: { extension: ExtensionEmails }) {
    const qc = useQueryClient();
    const push = useFlashes(s => s.push);
    const [value, setValue] = useState(String(extension.hourly_limit));
    const dirty = value !== String(extension.hourly_limit);

    const save = useMutation({
        mutationFn: () => updateExtensionEmailLimit(extension.id, Number(value)),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: EXTENSION_EMAILS_KEY });
            push({ type: 'success', message: m['admin.email.extensions.limitSaved']() });
        },
        onError: err => push({ type: 'error', message: firstError(err) ?? m['admin.email.extensions.limitError']() }),
    });

    return (
        <div className="mt-4 flex flex-col gap-3 border-t border-[var(--color-border)] pt-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0">
                <p className="text-sm font-medium text-[var(--color-ink)]">{m['admin.email.extensions.hourlyLimit']()}</p>
                <p className="mt-0.5 text-xs text-[var(--color-ink-muted)]">{m['admin.email.extensions.hourlyLimitHint']()}</p>
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <Input
                    type="number"
                    min={1}
                    max={10000}
                    value={value}
                    onChange={e => setValue(e.target.value)}
                    className="w-28"
                    aria-label={m['admin.email.extensions.hourlyLimit']()}
                />
                <Button size="sm" onClick={() => save.mutate()} disabled={!dirty || save.isPending || Number(value) < 1}>
                    {m['common.actions.save']()}
                </Button>
            </div>
        </div>
    );
}
