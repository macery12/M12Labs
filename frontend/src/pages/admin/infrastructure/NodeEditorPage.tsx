import { m } from '@/i18n/messages';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate, useParams, Link } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Server, Gauge, Network, SlidersHorizontal, CreditCard } from 'lucide-react';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { SectionCard, FieldGrid, FieldRow, SaveBar, ToggleGroup, ToggleRow } from '@/components/ui/editorChrome';
import { useFlashes } from '@/state/flashes';
import { useFlags } from '@/state/flags';
import { firstError, applyFieldErrors } from '@/lib/apiError';
import { createNode, updateNode, getNode, type NodeFormValues } from '@/api/nodes';
import { getDatabaseHosts } from '@/api/adminDatabases';
import { formatMib } from '@/lib/format';

type FormShape = NodeFormValues;

const DEFAULTS: FormShape = {
    name: '',
    description: '',
    fqdn: '',
    scheme: 'https',
    behind_proxy: false,
    public: true,
    // Both default to 0 in the schema — a new node opts into billing placement
    // explicitly rather than silently accepting purchases the moment it exists.
    deployable: false,
    deployable_free: false,
    memory: 4096,
    memory_overallocate: 0,
    disk: 51200,
    disk_overallocate: 0,
    listen_port_http: 8080,
    public_port_http: 8080,
    listen_port_sftp: 2022,
    public_port_sftp: 2022,
    daemon_base: '/var/lib/pterodactyl/volumes',
    upload_size: 100,
    database_host_id: null,
};

// Mirrors Node::$validationRules so a bad value is caught here rather than
// coming back as an opaque 422. Keep these in sync with app/Models/Node.php.
const NAME_PATTERN = /^[\w .-]{1,100}$/;
const PORT = { min: 1, max: 65535 };

type OverMode = 'none' | 'percent' | 'unlimited';

const overMode = (value: number): OverMode => (value < 0 ? 'unlimited' : value === 0 ? 'none' : 'percent');

/**
 * Over-allocation as a choice instead of a number with magic values: the
 * column stores -1 for unlimited and 0 for none, which the form used to ask
 * admins to type. The percent input only appears for "Allow up to".
 */
function OverallocateField({
    label,
    value,
    onMode,
    error,
    children,
}: {
    label: string;
    value: number;
    onMode: (next: number) => void;
    error?: string;
    children: React.ReactNode;
}) {
    const mode = overMode(value);

    return (
        <FieldRow label={label} desc={m['admin.infrastructure.node.field.overHint']()} error={error}>
            <div className="flex gap-2">
                <div className="min-w-0 flex-1">
                    <Select
                        value={mode}
                        onChange={v => onMode(v === 'unlimited' ? -1 : v === 'none' ? 0 : value > 0 ? value : 25)}
                        options={[
                            { value: 'none', label: m['admin.infrastructure.node.field.overMode.none']() },
                            { value: 'percent', label: m['admin.infrastructure.node.field.overMode.percent']() },
                            { value: 'unlimited', label: m['admin.infrastructure.node.field.overMode.unlimited']() },
                        ]}
                    />
                </div>
                {mode === 'percent' && (
                    <div className="relative w-28 shrink-0">
                        {children}
                        <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center font-mono text-xs text-[var(--color-ink-faint)]">
                            %
                        </span>
                    </div>
                )}
            </div>
        </FieldRow>
    );
}

export default function NodeEditorPage() {
    const navigate = useNavigate();
    const qc = useQueryClient();
    const { push } = useFlashes();
    const { id } = useParams<'id'>();
    const editing = Boolean(id);
    const [saving, setSaving] = useState(false);

    const { data: node, isLoading } = useQuery({
        queryKey: ['admin', 'node', id],
        queryFn: () => getNode(id!),
        enabled: editing,
    });

    const hostsQ = useQuery({ queryKey: ['admin', 'database-hosts'], queryFn: getDatabaseHosts });
    const flags = useFlags(s => s.everest);

    const {
        register,
        handleSubmit,
        watch,
        setValue,
        setError,
        reset,
        formState: { errors, isDirty },
    } = useForm<FormShape>({
        // `values` (not `defaultValues`) so the form resyncs once the edit
        // target arrives from the query.
        values: node
            ? {
                  name: node.name,
                  description: node.description ?? '',
                  fqdn: node.fqdn,
                  scheme: node.scheme,
                  behind_proxy: node.isBehindProxy,
                  public: node.isPublic,
                  deployable: node.deployable,
                  deployable_free: node.deployableFree,
                  memory: node.memory,
                  memory_overallocate: node.memoryOverallocate,
                  disk: node.disk,
                  disk_overallocate: node.diskOverallocate,
                  listen_port_http: node.ports.httpListen,
                  public_port_http: node.ports.httpPublic,
                  listen_port_sftp: node.ports.sftpListen,
                  public_port_sftp: node.ports.sftpPublic,
                  daemon_base: node.daemonBase,
                  upload_size: node.uploadSize,
                  database_host_id: node.databaseHostId ?? null,
              }
            : DEFAULTS,
    });

    const req = { required: m['admin.infrastructure.common.required']() };
    const num = { required: m['admin.infrastructure.common.required'](), valueAsNumber: true };
    const port = {
        ...num,
        min: { value: PORT.min, message: m['admin.infrastructure.node.validation.port']() },
        max: { value: PORT.max, message: m['admin.infrastructure.node.validation.port']() },
    };

    const onSubmit = handleSubmit(async values => {
        setSaving(true);
        try {
            if (editing) {
                await updateNode(Number(id), values);
                await qc.invalidateQueries({ queryKey: ['admin', 'nodes'] });
                await qc.invalidateQueries({ queryKey: ['admin', 'node', id] });
                push({ type: 'success', message: m['admin.infrastructure.node.updated']() });
                reset(values); // re-baseline so the save bar goes clean
            } else {
                const created = await createNode(values);
                await qc.invalidateQueries({ queryKey: ['admin', 'nodes'] });
                push({ type: 'success', message: m['admin.infrastructure.node.created']() });
                // A new node does nothing until the daemon has its config, so
                // land on the tab that has it.
                navigate(`/admin/infrastructure/nodes/${created.id}?tab=configuration`);
            }
        } catch (err) {
            // Attach per-field 422s inline; only fall back to a toast when the
            // failure isn't field-specific.
            if (!applyFieldErrors(err, setError)) {
                push({ type: 'error', message: firstError(err) ?? m['common.states.genericError']() });
            }
        } finally {
            setSaving(false);
        }
    });

    if (editing && isLoading) {
        return (
            <div className="flex min-h-[40vh] items-center justify-center">
                <Spinner className="h-6 w-6" />
            </div>
        );
    }

    const backTo = editing ? `/admin/infrastructure/nodes/${id}` : '/admin/infrastructure';

    // eslint-disable-next-line react-hooks/incompatible-library -- react-hook-form watch() opts out of the react compiler
    const scheme = watch('scheme');
    const memory = watch('memory');
    const disk = watch('disk');
    const memoryOver = watch('memory_overallocate');
    const diskOver = watch('disk_overallocate');
    const approx = (mib: number) => (mib > 0 ? m['admin.infrastructure.node.field.mibApprox']({ size: formatMib(mib) }) : undefined);
    const overPercent = {
        ...num,
        min: { value: 1, message: m['admin.infrastructure.node.validation.overPercent']() },
    };

    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-5">
            <div>
                <Link
                    to={backTo}
                    className="inline-flex items-center gap-1.5 text-xs text-[var(--color-ink-faint)] transition-colors hover:text-[var(--color-ink)]"
                >
                    <ArrowLeft className="h-3.5 w-3.5" />
                    {editing ? node?.name : m['ui.labels.infrastructure']()}
                </Link>
                <h1 className="mt-1 truncate text-xl font-semibold text-[var(--color-ink)]">
                    {editing ? m['admin.infrastructure.node.editTitle']() : m['ui.labels.newNode']()}
                </h1>
                {!editing && (
                    <>
                        <p className="mt-0.5 text-sm text-[var(--color-ink-faint)]">{m['admin.infrastructure.node.createSubtitle']()}</p>
                        <p className="mt-0.5 text-sm text-[var(--color-ink-faint)]">{m['admin.infrastructure.node.createNext']()}</p>
                    </>
                )}
            </div>

            <div className="flex flex-col gap-5">
                <SectionCard
                    icon={Server}
                    title={m['admin.infrastructure.node.section.identity']()}
                    desc={m['admin.infrastructure.node.section.identityDesc']()}
                >
                    <FieldGrid>
                        <FieldRow label={m['ui.labels.name']()} error={errors.name?.message}>
                            <Input
                                invalid={!!errors.name}
                                {...register('name', {
                                    ...req,
                                    pattern: { value: NAME_PATTERN, message: m['admin.infrastructure.node.validation.name']() },
                                })}
                            />
                        </FieldRow>
                        <FieldRow label={m['admin.infrastructure.node.field.scheme']()}>
                            <Select
                                value={scheme}
                                onChange={v => setValue('scheme', v as 'http' | 'https', { shouldDirty: true })}
                                options={[
                                    { value: 'https', label: 'https' },
                                    { value: 'http', label: 'http' },
                                ]}
                            />
                        </FieldRow>
                        <FieldRow
                            label={m['admin.infrastructure.node.field.fqdn']()}
                            desc={m['admin.infrastructure.node.field.fqdnHint']()}
                            error={errors.fqdn?.message}
                        >
                            <Input invalid={!!errors.fqdn} placeholder="node.example.com" {...register('fqdn', req)} />
                        </FieldRow>
                        <FieldRow label={m['common.labels.description']()}>
                            <Input {...register('description')} />
                        </FieldRow>
                    </FieldGrid>
                    <ToggleGroup>
                        <ToggleRow
                            label={m['admin.infrastructure.node.field.behindProxy']()}
                            desc={m['admin.infrastructure.node.field.behindProxyDesc']()}
                            checked={watch('behind_proxy')}
                            onChange={v => setValue('behind_proxy', v, { shouldDirty: true })}
                        />
                        <ToggleRow
                            label={m['admin.infrastructure.node.field.public']()}
                            desc={m['admin.infrastructure.node.field.publicDesc']()}
                            checked={watch('public')}
                            onChange={v => setValue('public', v, { shouldDirty: true })}
                        />
                    </ToggleGroup>
                </SectionCard>

                {/* Purely billing-side flags, so the card follows the billing
                    module's switch. Flags null == not loaded yet: fail open,
                    same as the router's FeatureGate. */}
                {(flags == null || flags.billing.enabled) && (
                    <SectionCard
                        icon={CreditCard}
                        title={m['admin.infrastructure.node.section.deployment']()}
                        desc={m['admin.infrastructure.node.section.deploymentDesc']()}
                    >
                        <ToggleGroup>
                            <ToggleRow
                                label={m['admin.infrastructure.node.field.deployable']()}
                                desc={m['admin.infrastructure.node.field.deployableDesc']()}
                                checked={watch('deployable')}
                                onChange={v => setValue('deployable', v, { shouldDirty: true })}
                            />
                            <ToggleRow
                                label={m['admin.infrastructure.node.field.deployableFree']()}
                                desc={m['admin.infrastructure.node.field.deployableFreeDesc']()}
                                checked={watch('deployable_free')}
                                onChange={v => setValue('deployable_free', v, { shouldDirty: true })}
                            />
                        </ToggleGroup>
                    </SectionCard>
                )}

                <SectionCard
                    icon={Gauge}
                    title={m['admin.infrastructure.node.section.capacity']()}
                    desc={m['admin.infrastructure.node.section.capacityDesc']()}
                >
                    <FieldGrid>
                        <FieldRow
                            label={m['admin.infrastructure.node.field.memory']()}
                            mono="MiB"
                            desc={approx(memory)}
                            error={errors.memory?.message}
                        >
                            <Input
                                type="number"
                                min={1}
                                invalid={!!errors.memory}
                                {...register('memory', { ...num, min: { value: 1, message: m['admin.infrastructure.node.validation.minOne']() } })}
                            />
                        </FieldRow>
                        <OverallocateField
                            label={m['admin.infrastructure.node.field.memoryOver']()}
                            value={memoryOver}
                            onMode={v => setValue('memory_overallocate', v, { shouldDirty: true, shouldValidate: true })}
                            error={errors.memory_overallocate?.message}
                        >
                            <Input
                                type="number"
                                min={1}
                                className="pr-7"
                                aria-label={m['admin.infrastructure.node.field.memoryOver']()}
                                invalid={!!errors.memory_overallocate}
                                {...register('memory_overallocate', overPercent)}
                            />
                        </OverallocateField>
                        <FieldRow
                            label={m['admin.infrastructure.node.field.disk']()}
                            mono="MiB"
                            desc={approx(disk)}
                            error={errors.disk?.message}
                        >
                            <Input
                                type="number"
                                min={1}
                                invalid={!!errors.disk}
                                {...register('disk', { ...num, min: { value: 1, message: m['admin.infrastructure.node.validation.minOne']() } })}
                            />
                        </FieldRow>
                        <OverallocateField
                            label={m['admin.infrastructure.node.field.diskOver']()}
                            value={diskOver}
                            onMode={v => setValue('disk_overallocate', v, { shouldDirty: true, shouldValidate: true })}
                            error={errors.disk_overallocate?.message}
                        >
                            <Input
                                type="number"
                                min={1}
                                className="pr-7"
                                aria-label={m['admin.infrastructure.node.field.diskOver']()}
                                invalid={!!errors.disk_overallocate}
                                {...register('disk_overallocate', overPercent)}
                            />
                        </OverallocateField>
                    </FieldGrid>
                </SectionCard>

                <SectionCard
                    icon={Network}
                    title={m['ui.labels.ports']()}
                    desc={m['admin.infrastructure.node.section.portsDesc']()}
                >
                    <FieldGrid>
                        <FieldRow label={m['admin.infrastructure.node.field.listenHttp']()} error={errors.listen_port_http?.message}>
                            <Input type="number" min={PORT.min} max={PORT.max} invalid={!!errors.listen_port_http} {...register('listen_port_http', port)} />
                        </FieldRow>
                        <FieldRow label={m['admin.infrastructure.node.field.publicHttp']()} error={errors.public_port_http?.message}>
                            <Input type="number" min={PORT.min} max={PORT.max} invalid={!!errors.public_port_http} {...register('public_port_http', port)} />
                        </FieldRow>
                        <FieldRow label={m['admin.infrastructure.node.field.listenSftp']()} error={errors.listen_port_sftp?.message}>
                            <Input type="number" min={PORT.min} max={PORT.max} invalid={!!errors.listen_port_sftp} {...register('listen_port_sftp', port)} />
                        </FieldRow>
                        <FieldRow label={m['admin.infrastructure.node.field.publicSftp']()} error={errors.public_port_sftp?.message}>
                            <Input type="number" min={PORT.min} max={PORT.max} invalid={!!errors.public_port_sftp} {...register('public_port_sftp', port)} />
                        </FieldRow>
                    </FieldGrid>
                </SectionCard>

                <SectionCard
                    icon={SlidersHorizontal}
                    title={m['ui.labels.advanced']()}
                    desc={m['admin.infrastructure.node.section.advancedDesc']()}
                >
                    {/* Full width: an absolute path needs the room. */}
                    <FieldRow
                        wide
                        label={m['admin.infrastructure.node.field.daemonBase']()}
                        desc={m['admin.infrastructure.node.field.daemonBaseHint']()}
                        error={errors.daemon_base?.message}
                    >
                        <Input invalid={!!errors.daemon_base} {...register('daemon_base')} />
                    </FieldRow>
                    <FieldGrid>
                        <FieldRow
                            label={m['admin.infrastructure.node.field.uploadSize']()}
                            mono="MiB"
                            error={errors.upload_size?.message}
                        >
                            <Input
                                type="number"
                                min={1}
                                max={1024}
                                invalid={!!errors.upload_size}
                                {...register('upload_size', {
                                    valueAsNumber: true,
                                    min: { value: 1, message: m['admin.infrastructure.node.validation.uploadSize']() },
                                    max: { value: 1024, message: m['admin.infrastructure.node.validation.uploadSize']() },
                                })}
                            />
                        </FieldRow>
                        <FieldRow
                            label={m['admin.infrastructure.node.field.databaseHost']()}
                            desc={m['admin.infrastructure.node.field.databaseHostHint']()}
                        >
                            <Select
                                value={watch('database_host_id') == null ? '' : String(watch('database_host_id'))}
                                onChange={v => setValue('database_host_id', v === '' ? null : Number(v), { shouldDirty: true })}
                                options={[
                                    { value: '', label: m['ui.states.none']() },
                                    ...(hostsQ.data ?? []).map(h => ({ value: String(h.id), label: `${h.name} (${h.host}:${h.port})` })),
                                ]}
                                placeholder={m['ui.states.none']()}
                            />
                        </FieldRow>
                    </FieldGrid>
                </SectionCard>
            </div>

            <SaveBar dirty={isDirty} saving={saving} onDiscard={() => reset()} />
        </form>
    );
}
