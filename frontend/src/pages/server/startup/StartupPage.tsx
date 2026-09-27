import { lazy, Suspense, useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Box, Check, ChevronDown, Copy, Gamepad2, RotateCcw, SlidersHorizontal, Terminal } from 'lucide-react';
import { m } from '@/i18n/messages';
import { can } from '@/lib/can';
import { cn } from '@/lib/cn';
import { withoutReinstallHint } from '@/lib/eggText';
import { SocketRequest } from '@/lib/Websocket';
import { useServer } from '@/components/server/ServerContext';
import { useServerSocket } from '@/state/serverSocket';
import { useFlashes } from '@/state/flashes';
import {
    getStartup,
    updateStartupVariable,
    setDockerImage,
    VERSION_HELPER_VARIABLES,
    type EggVariable,
    type StartupData,
} from '@/api/startup';
import { SectionCard, FieldGrid } from '@/components/ui/editorChrome';
import { ReadOnlyValue } from '@/components/ui/ReadOnlyValue';
import { ErrorState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { Spinner } from '@/components/ui/Spinner';
import { Button } from '@/components/ui/Button';

const VersionPickerModal = lazy(() => import('./VersionPickerModal'));

// Laid out by what people come here to do. The page used to open on the raw
// java command line, which nobody edits, and ended with the game version as a
// plain text box: typing a new version there saved it, but nothing downloaded
// it until a reinstall, so the server kept running the old one.
//
//   1. Game version — shown large, changed only through the picker, which
//      saves and reinstalls in one step.
//   2. Server options — the other variables; they autosave and apply on the
//      next start (D8).
//   3. Docker image — also applies on the next start.
//   4. Startup command — read-only, folded away.

type FieldKind = 'switch' | 'select' | 'text';

function fieldKind(variable: EggVariable): { kind: FieldKind; choices: string[] } {
    const isSwitch = variable.rules.some(r => r === 'boolean' || r === 'in:0,1' || r === 'in:true,false');
    if (isSwitch) return { kind: 'switch', choices: [] };
    const choices = variable.rules.find(r => r.startsWith('in:'))?.slice(3).split(',') ?? [];
    return choices.length > 0 ? { kind: 'select', choices } : { kind: 'text', choices: [] };
}

/** Version variables that get the picker instead of a text box. */
function isVersionVariable(variable: EggVariable): boolean {
    return VERSION_HELPER_VARIABLES.has(variable.envVariable) && fieldKind(variable).kind === 'text';
}

export default function StartupPage() {
    const server = useServer();
    const held = server.permissions;
    const canUpdate = can(held, 'startup.update');
    const canUpdateImage = can(held, 'startup.docker-image');
    const canReinstall = can(held, 'settings.reinstall');
    const canRestart = can(held, 'control.restart');

    const qc = useQueryClient();
    const key = ['server', server.id, 'startup'];

    const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
        queryKey: key,
        queryFn: () => getStartup(server.uuid),
    });

    // A change saved while the server runs waits for the next start. Say so
    // once, with the button that does it, until the server goes down or
    // starts again.
    const status = useServerSocket(s => s.status);
    const instance = useServerSocket(s => s.instance);
    const [needsRestart, setNeedsRestart] = useState(false);
    useEffect(
        () =>
            useServerSocket.subscribe((next, prev) => {
                if (next.status !== prev.status && (next.status === 'starting' || next.status === 'offline')) {
                    setNeedsRestart(false);
                }
            }),
        [],
    );
    const onSaved = () => {
        if (status === 'running' || status === 'starting') setNeedsRestart(true);
    };

    if (isLoading) {
        return (
            <div className="flex justify-center py-20">
                <Spinner className="h-6 w-6" />
            </div>
        );
    }
    if (isError || !data) {
        return <ErrorState error={error} onRetry={() => refetch()} retrying={isFetching} />;
    }

    // Context for version-dependent providers (e.g. Paper builds need the MC version).
    const versionContext: Record<string, string> = {};
    for (const v of data.variables) {
        if (v.envVariable === 'MINECRAFT_VERSION' || v.envVariable === 'MC_VERSION') {
            versionContext[v.envVariable] = v.serverValue ?? v.defaultValue ?? '';
        }
    }
    const jarVar = data.variables.find(v => v.envVariable === 'SERVER_JARFILE');
    const serverJar = jarVar?.serverValue ?? jarVar?.defaultValue ?? '';

    const versions = data.variables.filter(isVersionVariable);
    const options = data.variables.filter(v => !isVersionVariable(v));

    return (
        <div className="flex flex-col gap-6">
            <div>
                <h1 className="text-xl font-semibold text-[var(--color-ink)]">{m['server.startup.title']()}</h1>
                <p className="mt-1 text-sm text-[var(--color-ink-muted)]">{m['server.startup.subtitle']()}</p>
            </div>

            {needsRestart && status === 'running' && (
                <div
                    role="status"
                    className="flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-card)] border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 px-4 py-3"
                >
                    <p className="text-sm text-[var(--color-ink)]">{m['server.startup.restartToApply']()}</p>
                    {canRestart && (
                        <Button size="sm" variant="outline" onClick={() => instance?.send(SocketRequest.SET_STATE, 'restart')}>
                            <RotateCcw className="h-4 w-4" /> {m['common.power.restart']()}
                        </Button>
                    )}
                </div>
            )}

            {versions.length > 0 && (
                <SectionCard icon={Gamepad2} title={m['server.startup.version.title']()} desc={m['server.startup.version.desc']()}>
                    <div className="flex flex-col divide-y divide-[var(--color-border)]">
                        {versions.map(v => (
                            <VersionRow
                                key={v.envVariable}
                                variable={v}
                                label={versions.length === 1 ? m['server.startup.version.change']() : m['common.actions.change']()}
                                canEdit={canUpdate && v.isEditable}
                                canReinstall={canReinstall}
                                context={versionContext}
                                serverJar={serverJar}
                                onSaved={() => qc.invalidateQueries({ queryKey: key })}
                            />
                        ))}
                    </div>
                </SectionCard>
            )}

            <SectionCard
                icon={SlidersHorizontal}
                title={m['server.startup.options.title']()}
                desc={canUpdate ? m['server.startup.variablesAutosave']() : m['server.startup.options.readOnly']()}
            >
                {options.length === 0 ? (
                    <p className="text-sm text-[var(--color-ink-muted)]">{m['server.startup.noVariables']()}</p>
                ) : (
                    <FieldGrid>
                        {options.map(v => (
                            <VariableField
                                key={v.envVariable}
                                variable={v}
                                canEdit={canUpdate && v.isEditable}
                                queryKey={key}
                                onSaved={onSaved}
                            />
                        ))}
                    </FieldGrid>
                )}
            </SectionCard>

            <SectionCard icon={Box} title={m['server.startup.imageTitle']()} desc={m['server.startup.imageDesc']()}>
                <FieldGrid>
                    <DockerImage
                        data={data}
                        current={server.dockerImage}
                        canUpdate={canUpdateImage}
                        onSaved={() => {
                            qc.invalidateQueries({ queryKey: key });
                            onSaved();
                        }}
                    />
                </FieldGrid>
            </SectionCard>

            <CommandCard invocation={data.invocation} />
        </div>
    );
}

// ---- Game version -----------------------------------------------------------

function VersionRow({
    variable,
    label,
    canEdit,
    canReinstall,
    context,
    serverJar,
    onSaved,
}: {
    variable: EggVariable;
    /** "Change version" alone; plain "Change" when rows need telling apart. */
    label: string;
    canEdit: boolean;
    canReinstall: boolean;
    context: Record<string, string>;
    serverJar: string;
    onSaved: () => void;
}) {
    const [pickerOpen, setPickerOpen] = useState(false);
    const value = variable.serverValue || variable.defaultValue;
    const description = withoutReinstallHint(variable.description);

    return (
        <div className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                <p className="text-xs font-medium text-[var(--color-ink-muted)]">{variable.name}</p>
                <p className="mt-0.5 truncate font-mono text-lg font-semibold text-[var(--color-ink)]">{value || '—'}</p>
                {description && <p className="mt-1 max-w-2xl text-xs text-[var(--color-ink-faint)]">{description}</p>}
            </div>
            {canEdit && (
                <Button
                    variant="outline"
                    className="shrink-0"
                    onClick={() => setPickerOpen(true)}
                    aria-label={`${label}: ${variable.name}`}
                >
                    {label}
                </Button>
            )}

            {pickerOpen && (
                <Suspense fallback={null}>
                    <VersionPickerModal
                        variable={variable}
                        context={context}
                        serverJar={serverJar}
                        canReinstall={canReinstall}
                        onClose={() => setPickerOpen(false)}
                        onSaved={onSaved}
                    />
                </Suspense>
            )}
        </div>
    );
}

// ---- Server options ---------------------------------------------------------

function VariableField({
    variable,
    canEdit,
    queryKey,
    onSaved,
}: {
    variable: EggVariable;
    canEdit: boolean;
    queryKey: unknown[];
    onSaved: () => void;
}) {
    const server = useServer();
    const push = useFlashes(s => s.push);
    const qc = useQueryClient();
    const [value, setValue] = useState(variable.serverValue ?? '');
    const { kind, choices } = fieldKind(variable);
    const description = withoutReinstallHint(variable.description);

    // Fields save on blur or toggle. A spinner that vanished was the only sign
    // anything happened, so a short "Saved" stays where it was.
    const [justSaved, setJustSaved] = useState(false);
    useEffect(() => {
        if (!justSaved) return;
        const t = setTimeout(() => setJustSaved(false), 2500);
        return () => clearTimeout(t);
    }, [justSaved]);

    const save = useMutation({
        mutationFn: (next: string) => updateStartupVariable(server.uuid, variable.envVariable, next),
        onMutate: () => setJustSaved(false),
        onSuccess: ({ invocation }, saved) => {
            setJustSaved(true);
            onSaved();
            qc.setQueryData<StartupData>(queryKey as string[], prev =>
                prev
                    ? {
                          ...prev,
                          invocation,
                          variables: prev.variables.map(v =>
                              v.envVariable === variable.envVariable ? { ...v, serverValue: saved } : v,
                          ),
                      }
                    : prev,
            );
        },
        onError: () => {
            setValue(variable.serverValue ?? '');
            push({ type: 'error', message: m['common.states.genericError']() });
        },
    });

    const commit = (next: string) => {
        setValue(next);
        if (next !== (variable.serverValue ?? '')) save.mutate(next);
    };

    const on = value === '1' || value === 'true';
    const id = `var-${variable.envVariable}`;

    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <div className="flex h-5 items-center justify-between gap-2">
                <label htmlFor={id} className="flex min-w-0 items-baseline gap-2 text-sm font-medium text-[var(--color-ink-muted)]">
                    <span className="truncate">{variable.name}</span>
                    <code className="hidden shrink-0 font-mono text-[10px] text-[var(--color-ink-faint)] sm:inline">
                        {variable.envVariable}
                    </code>
                </label>
                {save.isPending ? (
                    <Spinner className="h-3.5 w-3.5 shrink-0" />
                ) : justSaved ? (
                    <span role="status" className="flex shrink-0 items-center gap-1 text-[11px] font-medium text-[var(--color-accent)]">
                        <Check className="h-3.5 w-3.5" />
                        {m['common.states.saved']()}
                    </span>
                ) : null}
            </div>

            {kind === 'switch' ? (
                <div className="flex min-h-10 items-center gap-2.5">
                    <Switch
                        checked={on}
                        onChange={next => commit(next ? '1' : '0')}
                        disabled={!canEdit || save.isPending}
                        label={variable.name}
                    />
                    <span className="text-xs text-[var(--color-ink-muted)]">
                        {on ? m['common.states.enabled']() : m['common.states.disabled']()}
                    </span>
                </div>
            ) : kind === 'select' ? (
                <Select
                    id={id}
                    value={value}
                    onChange={commit}
                    options={choices.map(o => ({ label: o, value: o }))}
                    disabled={!canEdit || save.isPending}
                    className="w-full"
                />
            ) : canEdit ? (
                <Input
                    id={id}
                    value={value}
                    onChange={e => setValue(e.target.value)}
                    onBlur={() => commit(value)}
                    placeholder={variable.defaultValue}
                    className="w-full font-mono text-xs"
                />
            ) : (
                <span className="flex min-h-10 items-center truncate font-mono text-xs text-[var(--color-ink)]">
                    {value || variable.defaultValue || '—'}
                </span>
            )}

            {description && <span className="text-xs text-[var(--color-ink-faint)]">{description}</span>}
        </div>
    );
}

// ---- Docker image -----------------------------------------------------------

function DockerImage({
    data,
    current,
    canUpdate,
    onSaved,
}: {
    data: StartupData;
    current: string;
    canUpdate: boolean;
    onSaved: () => void;
}) {
    const server = useServer();
    const push = useFlashes(s => s.push);
    const options = Object.entries(data.dockerImages).map(([label, value]) => ({ label, value }));
    // Match V1: compare case-insensitively so an egg image that differs only in
    // casing still resolves to a dropdown selection rather than a "custom" lock.
    const currentLc = current.toLowerCase();
    const matched = options.find(o => o.value.toLowerCase() === currentLc);
    const isCustom = options.length > 0 && !matched;

    const save = useMutation({
        mutationFn: (image: string) => setDockerImage(server.uuid, image),
        onSuccess: () => {
            push({ type: 'success', message: m['server.startup.imageSaved']() });
            onSaved();
        },
        onError: () => push({ type: 'error', message: m['common.states.genericError']() }),
    });

    if (canUpdate && options.length > 1 && !isCustom) {
        return (
            <div className="flex min-w-0 flex-col gap-1.5">
                <div className="flex items-center gap-3">
                    <Select
                        value={matched?.value ?? current}
                        onChange={image => save.mutate(image)}
                        options={options}
                        disabled={save.isPending}
                        className="w-full"
                    />
                    {save.isPending && <Spinner className="h-4 w-4 shrink-0" />}
                </div>
                <span className="font-mono text-[10px] text-[var(--color-ink-faint)]">{current}</span>
            </div>
        );
    }

    return (
        <ReadOnlyValue
            label={matched?.label ?? m['server.startup.imageTitle']()}
            desc={isCustom ? m['server.startup.imageCustom']() : undefined}
            mono
        >
            {current}
        </ReadOnlyValue>
    );
}

// ---- Startup command --------------------------------------------------------

function CommandCard({ invocation }: { invocation: string }) {
    const [open, setOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const copy = () => {
        navigator.clipboard?.writeText(invocation).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1600);
        });
    };

    return (
        <SectionCard
            icon={Terminal}
            title={m['server.startup.commandTitle']()}
            desc={m['server.startup.commandDesc']()}
            right={
                <div className="flex items-center gap-2">
                    {open && (
                        <Button variant="ghost" size="sm" onClick={copy}>
                            {copied ? <Check className="h-4 w-4 text-[var(--color-accent)]" /> : <Copy className="h-4 w-4" />}
                            {copied ? m['common.states.copied']() : m['server.startup.copyCommand']()}
                        </Button>
                    )}
                    <Button variant="outline" size="sm" onClick={() => setOpen(o => !o)} aria-expanded={open}>
                        <ChevronDown className={cn('h-4 w-4 transition-transform', open && 'rotate-180')} />
                        {open ? m['common.actions.hide']() : m['server.startup.showCommand']()}
                    </Button>
                </div>
            }
        >
            {open && (
                <pre className="whitespace-pre-wrap break-all rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface-2)] p-4 font-mono text-xs leading-relaxed text-[var(--color-ink)]">
                    {invocation}
                </pre>
            )}
        </SectionCard>
    );
}
