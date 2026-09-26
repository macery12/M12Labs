import { useState } from 'react';
import { Pencil, Plus, Trash2, Variable, X } from 'lucide-react';
import { m, td } from '@/i18n/messages';
import { cn } from '@/lib/cn';
import { firstError } from '@/lib/apiError';
import { useFlashes } from '@/state/flashes';
import { Button } from '@/components/ui/Button';
import { Input, Field } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { Modal } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Spinner } from '@/components/ui/Spinner';
import { SectionCard } from '@/components/ui/editorChrome';
import {
    createEggVariable,
    deleteEggVariable,
    type AdminEggVariable,
    type EggFieldType,
    type NewEggVariable,
} from '@/api/adminNests';

const RULE_SUGGESTIONS = ['required', 'nullable', 'string', 'numeric', 'boolean', 'ip', 'alpha_num'];

const FIELD_TYPES: EggFieldType[] = ['text', 'password', 'number', 'boolean'];

const EMPTY_DRAFT: NewEggVariable = {
    name: '',
    description: '',
    environmentVariable: '',
    defaultValue: '',
    isUserViewable: false,
    isUserEditable: false,
    fieldType: 'text',
    rules: '',
};

function parseRules(input: string): string[] {
    return (input || '')
        .split('|')
        .map(r => r.trim())
        .filter(r => r.length > 0);
}

function typeBadgeClass(type: EggFieldType): string {
    switch (type) {
        case 'password':
            return 'bg-[var(--color-danger)]/10 text-[var(--color-danger)] border-[var(--color-danger)]/40';
        case 'number':
            return 'bg-[var(--brand-soft)] text-[var(--brand-bright)] border-[var(--brand)]/40';
        case 'boolean':
            return 'bg-[var(--color-warning)]/10 text-[var(--color-warning)] border-[var(--color-warning)]/40';
        default:
            return 'bg-[var(--color-accent)]/10 text-[var(--color-accent)] border-[var(--color-accent)]/40';
    }
}

// ─── Rules chip builder ───────────────────────────────────────────────────────

function RulesBuilder({ value, onChange }: { value: string; onChange: (next: string) => void }) {
    const current = parseRules(value);
    const [draft, setDraft] = useState('');

    const setRules = (next: string[]) => onChange(next.join('|'));

    const add = () => {
        const next = draft.trim();
        if (!next || current.includes(next)) return;
        setRules([...current, next]);
        setDraft('');
    };

    return (
        <div className="flex flex-col gap-2">
            <label className="text-sm font-medium text-[var(--color-ink-muted)]">
                {m['admin.nests.egg.variables.rules']()}
            </label>

            {current.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {current.map(rule => (
                        <button
                            type="button"
                            key={rule}
                            onClick={() => setRules(current.filter(r => r !== rule))}
                            className="inline-flex items-center gap-1 rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface-2)] px-2 py-1 text-xs text-[var(--color-ink)] hover:border-[var(--color-danger)]/50"
                        >
                            {rule} <X className="h-3 w-3" />
                        </button>
                    ))}
                </div>
            )}

            <div className="flex gap-2">
                <Input
                    className="h-9"
                    value={draft}
                    placeholder={m['admin.nests.egg.variables.rulePlaceholder']()}
                    onChange={e => setDraft(e.currentTarget.value)}
                    onKeyDown={e => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            add();
                        }
                    }}
                />
                <Button type="button" variant="outline" size="sm" onClick={add}>
                    {m['admin.nests.egg.variables.addRule']()}
                </Button>
            </div>

            <div className="flex flex-wrap gap-2">
                {RULE_SUGGESTIONS.map(rule => (
                    <button
                        key={rule}
                        type="button"
                        onClick={() => !current.includes(rule) && setRules([...current, rule])}
                        className={cn(
                            'rounded-lg border px-2 py-1 text-xs transition-colors',
                            current.includes(rule)
                                ? 'border-[var(--color-accent)]/40 bg-[var(--color-accent)]/10 text-[var(--color-accent)]'
                                : 'border-[var(--color-border)] bg-[var(--color-surface-2)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]',
                        )}
                    >
                        {rule}
                    </button>
                ))}
            </div>
        </div>
    );
}

// ─── Shared variable form ─────────────────────────────────────────────────────

function VariableFields({
    draft,
    patch,
}: {
    draft: NewEggVariable;
    patch: (p: Partial<NewEggVariable>) => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            <Field label={m['admin.nests.egg.variables.name']()} htmlFor="var-name">
                <Input id="var-name" value={draft.name} onChange={e => patch({ name: e.currentTarget.value })} />
            </Field>

            <Field label={m['common.labels.description']()} htmlFor="var-desc">
                <Textarea
                    id="var-desc"
                    rows={2}
                    value={draft.description}
                    onChange={e => patch({ description: e.currentTarget.value })}
                />
            </Field>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label={m['admin.nests.egg.variables.envVariable']()} htmlFor="var-env">
                    <Input
                        id="var-env"
                        className="font-mono"
                        value={draft.environmentVariable}
                        onChange={e => patch({ environmentVariable: e.currentTarget.value })}
                    />
                </Field>
                <Field label={m['admin.nests.egg.variables.defaultValue']()} htmlFor="var-default">
                    <Input
                        id="var-default"
                        value={draft.defaultValue}
                        onChange={e => patch({ defaultValue: e.currentTarget.value })}
                    />
                </Field>
            </div>

            <Field label={m['admin.nests.egg.variables.fieldType']()} htmlFor="var-type">
                <Select
                    id="var-type"
                    value={draft.fieldType}
                    onChange={v => patch({ fieldType: v as EggFieldType })}
                    options={FIELD_TYPES.map(t => ({ value: t, label: td(`admin.nests.egg.variables.type.${t}`, t) }))}
                />
            </Field>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label className="flex items-center justify-between gap-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-2)]/40 px-4 py-3">
                    <span className="text-sm text-[var(--color-ink)]">{m['admin.nests.egg.variables.userViewable']()}</span>
                    <Switch
                        checked={draft.isUserViewable}
                        onChange={v => patch({ isUserViewable: v })}
                        label={m['admin.nests.egg.variables.userViewable']()}
                    />
                </label>
                <label className="flex items-center justify-between gap-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-2)]/40 px-4 py-3">
                    <span className="text-sm text-[var(--color-ink)]">{m['admin.nests.egg.variables.userEditable']()}</span>
                    <Switch
                        checked={draft.isUserEditable}
                        onChange={v => patch({ isUserEditable: v })}
                        label={m['admin.nests.egg.variables.userEditable']()}
                    />
                </label>
            </div>

            <RulesBuilder value={draft.rules} onChange={rules => patch({ rules })} />
        </div>
    );
}

// ─── Edit modal ───────────────────────────────────────────────────────────────

function EditVariableModal({
    variable,
    onClose,
    onSave,
}: {
    variable: AdminEggVariable;
    onClose: () => void;
    onSave: (next: AdminEggVariable) => void;
}) {
    const [draft, setDraft] = useState<AdminEggVariable>(variable);

    return (
        <Modal
            open
            onClose={onClose}
            title={draft.environmentVariable || draft.name || m['admin.nests.egg.variables.editTitle']()}
            size="lg"
            footer={
                <>
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        {m['common.actions.cancel']()}
                    </Button>
                    <Button size="sm" onClick={() => onSave(draft)}>
                        {m['common.actions.apply']()}
                    </Button>
                </>
            }
        >
            <VariableFields draft={draft} patch={p => setDraft(prev => ({ ...prev, ...p }))} />
        </Modal>
    );
}

// ─── New variable modal ───────────────────────────────────────────────────────

// Creating is its own API call and lands immediately — it is not part of the
// page's pending save.
function NewVariableModal({
    eggId,
    onClose,
    onCreated,
}: {
    eggId: number;
    onClose: () => void;
    onCreated: (variable: AdminEggVariable) => void;
}) {
    const push = useFlashes(s => s.push);
    const [draft, setDraft] = useState<NewEggVariable>(EMPTY_DRAFT);
    const [saving, setSaving] = useState(false);

    const submit = async () => {
        setSaving(true);
        try {
            const created = await createEggVariable(eggId, draft);
            push({ type: 'success', message: m['admin.nests.egg.variables.created']() });
            onCreated(created);
            onClose();
        } catch (err) {
            push({ type: 'error', message: firstError(err) ?? m['common.states.genericError']() });
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={m['admin.nests.egg.variables.newTitle']()}
            size="lg"
            footer={
                <>
                    <Button variant="ghost" size="sm" onClick={onClose} disabled={saving}>
                        {m['common.actions.cancel']()}
                    </Button>
                    <Button size="sm" onClick={submit} disabled={saving}>
                        {saving && <Spinner className="h-4 w-4" />}
                        {m['common.actions.create']()}
                    </Button>
                </>
            }
        >
            <VariableFields draft={draft} patch={p => setDraft(prev => ({ ...prev, ...p }))} />
        </Modal>
    );
}

// ─── Row ──────────────────────────────────────────────────────────────────────

function VariableRow({
    variable,
    dirty,
    onEdit,
    onDelete,
}: {
    variable: AdminEggVariable;
    dirty: boolean;
    onEdit: () => void;
    onDelete: () => void;
}) {
    const rules = parseRules(variable.rules);
    const isRequired = rules.includes('required');
    const defaultValue = variable.defaultValue.trim();

    return (
        <tr className="border-b border-[var(--color-border)] transition-colors last:border-b-0 hover:bg-[var(--color-surface-2)]/40">
            <td className="px-4 py-3">
                <button type="button" onClick={onEdit} className="group flex min-w-0 max-w-full flex-col text-left">
                    <span className="flex items-center gap-2 truncate font-mono text-xs font-semibold text-[var(--color-ink)] group-hover:underline">
                        {variable.environmentVariable || variable.name}
                        {dirty && (
                            <span
                                className="h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-warning)]"
                                title={m['common.editor.unsaved']()}
                            />
                        )}
                    </span>
                    {variable.name && variable.environmentVariable && (
                        <span className="mt-0.5 truncate text-xs text-[var(--color-ink-faint)]">{variable.name}</span>
                    )}
                </button>
            </td>
            <td className="w-24 px-3 py-3">
                <span className={cn('rounded border px-2 py-0.5 font-mono text-xs', typeBadgeClass(variable.fieldType))}>
                    {variable.fieldType}
                </span>
            </td>
            <td className="hidden w-40 px-3 py-3 md:table-cell">
                <span
                    className={cn(
                        'block max-w-[150px] truncate rounded bg-[var(--color-surface-2)] px-2 py-0.5 font-mono text-xs',
                        defaultValue ? 'text-[var(--color-ink-muted)]' : 'italic text-[var(--color-ink-faint)]',
                    )}
                    title={defaultValue || undefined}
                >
                    {defaultValue || m['admin.nests.egg.variables.noDefault']()}
                </span>
            </td>
            <td className="hidden px-3 py-3 lg:table-cell">
                <div className="flex flex-wrap gap-1.5">
                    {isRequired && (
                        <span className="rounded border border-[var(--brand)]/40 bg-[var(--brand-soft)] px-2 py-0.5 text-xs text-[var(--brand-bright)]">
                            {m['admin.nests.egg.variables.flag.required']()}
                        </span>
                    )}
                    {variable.isUserViewable && (
                        <span className="rounded border border-[var(--color-border-strong)] bg-[var(--color-surface-2)] px-2 py-0.5 text-xs text-[var(--color-ink-muted)]">
                            {m['admin.nests.egg.variables.flag.viewable']()}
                        </span>
                    )}
                    {variable.isUserEditable && (
                        <span className="rounded border border-[var(--color-accent)]/40 bg-[var(--color-accent)]/10 px-2 py-0.5 text-xs text-[var(--color-accent)]">
                            {m['admin.nests.egg.variables.flag.editable']()}
                        </span>
                    )}
                </div>
            </td>
            <td className="w-24 px-3 py-3">
                <div className="flex items-center justify-end gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        aria-label={m['common.actions.edit']()}
                        title={m['common.actions.edit']()}
                        onClick={onEdit}
                    >
                        <Pencil className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 text-[var(--color-ink-faint)] hover:text-[var(--color-danger)]"
                        aria-label={m['common.actions.delete']()}
                        title={m['common.actions.delete']()}
                        onClick={onDelete}
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                </div>
            </td>
        </tr>
    );
}

// ─── Section ──────────────────────────────────────────────────────────────────

// Controlled: the egg editor owns `rows` and their baseline, so variable edits
// ride the page's single save. Create and delete are immediate API calls and
// report back through onCreated / onDeleted so the page can fold them into both
// rows and baseline without disturbing other pending edits.
//
// There is deliberately no reordering: egg_variables has no sort column, and
// the old drag handle's order was discarded on save.
export function VariablesSection({
    eggId,
    rows,
    baseline,
    onRowsChange,
    onCreated,
    onDeleted,
}: {
    eggId: number | null;
    rows: AdminEggVariable[];
    baseline: AdminEggVariable[];
    onRowsChange: (rows: AdminEggVariable[]) => void;
    onCreated: (variable: AdminEggVariable) => void;
    onDeleted: (id: number) => void;
}) {
    const push = useFlashes(s => s.push);
    const [editing, setEditing] = useState<AdminEggVariable | null>(null);
    const [showNew, setShowNew] = useState(false);
    const [pendingDelete, setPendingDelete] = useState<AdminEggVariable | null>(null);
    const [busy, setBusy] = useState(false);

    const baselineById = new Map(baseline.map(v => [v.id, JSON.stringify(v)]));

    const confirmDelete = async () => {
        if (!pendingDelete || eggId === null) return;
        setBusy(true);
        try {
            await deleteEggVariable(eggId, pendingDelete.id);
            onDeleted(pendingDelete.id);
            push({ type: 'success', message: m['admin.nests.egg.variables.deleted']() });
        } catch (err) {
            push({ type: 'error', message: firstError(err) ?? m['common.states.genericError']() });
        } finally {
            setBusy(false);
            setPendingDelete(null);
        }
    };

    return (
        <SectionCard
            icon={Variable}
            title={m['admin.nests.egg.tabs.variables']()}
            desc={
                eggId === null
                    ? m['admin.nests.egg.section.variablesDesc']()
                    : m['admin.nests.egg.variables.count']({ count: rows.length })
            }
            right={
                eggId !== null && (
                    <Button type="button" variant="outline" size="sm" onClick={() => setShowNew(true)}>
                        <Plus className="h-4 w-4" /> {m['admin.nests.egg.variables.new']()}
                    </Button>
                )
            }
        >
            {eggId === null ? (
                <p className="rounded-lg border border-dashed border-[var(--color-border-strong)] px-6 py-10 text-center text-sm text-[var(--color-ink-faint)]">
                    {m['admin.nests.egg.variables.createFirst']()}
                </p>
            ) : rows.length === 0 ? (
                <p className="rounded-lg border border-dashed border-[var(--color-border-strong)] px-6 py-10 text-center text-sm text-[var(--color-ink-faint)]">
                    {m['admin.nests.egg.variables.empty']()}
                </p>
            ) : (
                <div className="-mx-5 -my-5 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-[var(--color-border)] bg-[var(--color-surface-2)]/50 text-left text-xs font-medium uppercase tracking-wider text-[var(--color-ink-faint)]">
                                <th className="px-4 py-2">{m['admin.nests.egg.variables.colVariable']()}</th>
                                <th className="w-24 px-3 py-2">{m['admin.nests.egg.variables.colType']()}</th>
                                <th className="hidden w-40 px-3 py-2 md:table-cell">{m['admin.nests.egg.variables.colDefault']()}</th>
                                <th className="hidden px-3 py-2 lg:table-cell">{m['admin.nests.egg.variables.colFlags']()}</th>
                                <th className="w-24 px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map(v => (
                                <VariableRow
                                    key={v.id}
                                    variable={v}
                                    dirty={baselineById.get(v.id) !== JSON.stringify(v)}
                                    onEdit={() => setEditing(v)}
                                    onDelete={() => setPendingDelete(v)}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {editing && (
                <EditVariableModal
                    variable={editing}
                    onClose={() => setEditing(null)}
                    onSave={next => {
                        onRowsChange(rows.map(v => (v.id === next.id ? next : v)));
                        setEditing(null);
                    }}
                />
            )}

            {showNew && eggId !== null && (
                <NewVariableModal eggId={eggId} onClose={() => setShowNew(false)} onCreated={onCreated} />
            )}

            <ConfirmDialog
                open={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                title={m['admin.nests.egg.variables.deleteTitle']()}
                body={m['admin.nests.egg.variables.deleteBody']()}
                confirmLabel={m['admin.nests.egg.variables.deleteConfirm']()}
                cancelLabel={m['common.actions.cancel']()}
                busy={busy}
                onConfirm={confirmDelete}
            />
        </SectionCard>
    );
}
