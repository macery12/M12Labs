import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { Link, useBlocker, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import * as Dropdown from '@radix-ui/react-dropdown-menu';
import {
    AlertTriangle,
    ArrowLeft,
    Check,
    Container,
    Copy,
    Download,
    FileCog,
    Info,
    MoreHorizontal,
    ScrollText,
    Settings2,
    SquareTerminal,
    Trash2,
    Variable,
    type LucideIcon,
} from 'lucide-react';
import { m, td } from '@/i18n/messages';
import { cn } from '@/lib/cn';
import { firstError } from '@/lib/apiError';
import { useFlashes } from '@/state/flashes';
import { useWideContent } from '@/components/shell/shellLayout';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { Spinner } from '@/components/ui/Spinner';
import { Modal } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { FieldGrid, FieldRow, SaveBar, SectionCard, ToggleGroup, ToggleRow } from '@/components/ui/editorChrome';
import {
    getEggDetail,
    createEgg,
    updateEgg,
    deleteEgg,
    updateEggVariables,
    dockerMapToRows,
    dockerRowsToMap,
    parseLines,
    type AdminEggDetail,
    type AdminEggVariable,
    type DockerRow,
    type EggPayload,
} from '@/api/adminNests';
import { CodeEditor } from './CodeEditor';
import { DockerImageManager } from './DockerImageManager';
import { VariablesSection } from './VariablesSection';
import { ExportEggModal } from './ExportEggModal';

// The egg editor's shared draft. Docker images + file denylist are edited in a
// friendlier shape than the API payload and collapsed on save.
interface EggForm {
    name: string;
    description: string;
    startup: string;
    configStop: string;
    updateUrl: string;
    dockerRows: DockerRow[];
    configStartup: string;
    configFiles: string;
    features: string[];
    fileDenylistText: string;
    forceOutgoingIp: boolean;
    scriptContainer: string;
    scriptEntry: string;
    scriptInstall: string;
    scriptIsPrivileged: boolean;
}

// Same defaults V1's NewEggContainer seeded.
const CREATE_DEFAULTS: EggForm = {
    name: '',
    description: '',
    startup: '',
    configStop: 'stop',
    updateUrl: '',
    dockerRows: [{ image: '', alias: '' }],
    configStartup: JSON.stringify({ done: [], strip_ansi: false, user_interaction: [] }, null, 4),
    configFiles: '{}',
    features: [],
    fileDenylistText: '',
    forceOutgoingIp: false,
    scriptContainer: 'ghcr.io/pterodactyl/installers:debian',
    scriptEntry: '/bin/bash',
    scriptInstall: '',
    scriptIsPrivileged: false,
};

function formFromEgg(egg: AdminEggDetail): EggForm {
    return {
        name: egg.name,
        description: egg.description ?? '',
        startup: egg.startup,
        configStop: egg.configStop ?? '',
        updateUrl: egg.updateUrl ?? '',
        dockerRows: dockerMapToRows(egg.dockerImages),
        configStartup: JSON.stringify(egg.configStartup ?? {}, null, 4),
        configFiles: JSON.stringify(egg.configFiles ?? {}, null, 4),
        features: egg.features,
        fileDenylistText: egg.fileDenylist.join('\n'),
        forceOutgoingIp: egg.forceOutgoingIp,
        scriptContainer: egg.scriptContainer,
        scriptEntry: egg.scriptEntry,
        scriptInstall: egg.scriptInstall ?? '',
        scriptIsPrivileged: egg.scriptIsPrivileged,
    };
}

function fullPayload(form: EggForm): EggPayload {
    return {
        name: form.name,
        description: form.description,
        startup: form.startup,
        configStop: form.configStop,
        updateUrl: form.updateUrl || null,
        dockerImages: dockerRowsToMap(form.dockerRows),
        configStartup: form.configStartup,
        configFiles: form.configFiles,
        features: form.features,
        fileDenylist: parseLines(form.fileDenylistText),
        forceOutgoingIp: form.forceOutgoingIp,
        scriptContainer: form.scriptContainer,
        scriptEntry: form.scriptEntry,
        scriptInstall: form.scriptInstall,
        scriptIsPrivileged: form.scriptIsPrivileged,
    };
}

// Which payload field each draft field is written through.
const PAYLOAD_KEY: Record<keyof EggForm, keyof EggPayload> = {
    name: 'name',
    description: 'description',
    startup: 'startup',
    configStop: 'configStop',
    updateUrl: 'updateUrl',
    dockerRows: 'dockerImages',
    configStartup: 'configStartup',
    configFiles: 'configFiles',
    features: 'features',
    fileDenylistText: 'fileDenylist',
    forceOutgoingIp: 'forceOutgoingIp',
    scriptContainer: 'scriptContainer',
    scriptEntry: 'scriptEntry',
    scriptInstall: 'scriptInstall',
    scriptIsPrivileged: 'scriptIsPrivileged',
};

type SectionId = 'general' | 'startup' | 'variables' | 'configuration' | 'install' | 'advanced';

const SECTIONS: { id: SectionId; label: () => string; icon: LucideIcon }[] = [
    { id: 'general', label: () => m['ui.labels.general'](), icon: Info },
    { id: 'startup', label: () => m['ui.labels.startup'](), icon: SquareTerminal },
    { id: 'variables', label: () => m['ui.labels.variables'](), icon: Variable },
    { id: 'configuration', label: () => m['ui.labels.configuration'](), icon: FileCog },
    { id: 'install', label: () => m['ui.actions.installScript'](), icon: ScrollText },
    { id: 'advanced', label: () => m['ui.labels.advanced'](), icon: Settings2 },
];

// The draft fields each section owns — drives the nav's unsaved dots.
const SECTION_KEYS: Record<Exclude<SectionId, 'variables'>, (keyof EggForm)[]> = {
    general: ['name', 'description', 'updateUrl'],
    startup: ['startup', 'configStop', 'dockerRows'],
    configuration: ['configStartup', 'configFiles'],
    install: ['scriptInstall', 'scriptContainer', 'scriptEntry', 'scriptIsPrivileged'],
    advanced: ['features', 'fileDenylistText', 'forceOutgoingIp'],
};

const FORM_KEYS = Object.keys(PAYLOAD_KEY) as (keyof EggForm)[];

const IMPLEMENTED_FEATURES = ['eula'] as const;

// Substituted by StartupCommandService or present in every server's
// environment, so a {{NAME}} for one of these is never a typo.
const PANEL_VARIABLES = ['SERVER_MEMORY', 'SERVER_IP', 'SERVER_PORT'];
const ENVIRONMENT_VARIABLES = [...PANEL_VARIABLES, 'STARTUP', 'P_SERVER_UUID', 'TZ'];

const IS_MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent);

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

// Syntax-checks a JSON block as it's typed. The engines disagree on the error
// text (V8 reports a character offset, Firefox a line/column), so pull out
// whichever is there and say it in our own words.
function jsonError(text: string): string | null {
    if (!text.trim()) return null;
    try {
        JSON.parse(text);
        return null;
    } catch (err) {
        const message = err instanceof Error ? err.message : '';
        const lineCol = /line (\d+) column (\d+)/.exec(message);
        if (lineCol) {
            return m['admin.nests.egg.json.invalidAt']({ line: Number(lineCol[1]), column: Number(lineCol[2]) });
        }
        const position = /position (\d+)/.exec(message);
        if (position) {
            const before = text.slice(0, Number(position[1])).split('\n');
            return m['admin.nests.egg.json.invalidAt']({
                line: before.length,
                column: (before[before.length - 1]?.length ?? 0) + 1,
            });
        }
        return m['admin.nests.egg.json.invalid']();
    }
}

export default function EggEditorPage() {
    useWideContent();
    const { nestId, eggId } = useParams<{ nestId: string; eggId: string }>();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const push = useFlashes(s => s.push);
    const isCreate = !eggId;
    const [searchParams, setSearchParams] = useSearchParams();

    const { data: egg, isLoading, isError } = useQuery({
        queryKey: ['admin', 'egg', eggId],
        queryFn: () => getEggDetail(Number(eggId)),
        enabled: !isCreate,
    });

    const requested = searchParams.get('section');
    const section: SectionId = SECTIONS.some(s => s.id === requested) ? (requested as SectionId) : 'general';
    const setSection = (id: SectionId) =>
        setSearchParams(
            prev => {
                const next = new URLSearchParams(prev);
                next.set('section', id);
                return next;
            },
            { replace: true },
        );

    const [form, setForm] = useState<EggForm>(CREATE_DEFAULTS);
    const [baseline, setBaseline] = useState<EggForm>(CREATE_DEFAULTS);
    const [varRows, setVarRows] = useState<AdminEggVariable[]>([]);
    const [varBaseline, setVarBaseline] = useState<AdminEggVariable[]>([]);
    const [saving, setSaving] = useState(false);
    const [showExport, setShowExport] = useState(false);
    const [showDelete, setShowDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    // Initialize the draft only when a different egg loads — so a background
    // refetch never wipes unsaved edits.
    const initializedFor = useRef<number | null>(null);
    useEffect(() => {
        if (isCreate || !egg) return;
        if (initializedFor.current === egg.id) return;
        initializedFor.current = egg.id;
        const next = formFromEgg(egg);
        setForm(next);
        setBaseline(next);
        setVarRows(egg.variables);
        setVarBaseline(egg.variables);
    }, [egg, isCreate]);

    const patch = (p: Partial<EggForm>) => setForm(prev => ({ ...prev, ...p }));

    const dirtyKeys = FORM_KEYS.filter(k => !same(form[k], baseline[k]));
    const varsDirty = !same(varRows, varBaseline);
    const dirty = dirtyKeys.length > 0 || varsDirty;

    const startupJsonError = useMemo(() => jsonError(form.configStartup), [form.configStartup]);
    const configFilesJsonError = useMemo(() => jsonError(form.configFiles), [form.configFiles]);
    const hasJsonError = startupJsonError !== null || configFilesJsonError !== null;

    const missingEssentials =
        isCreate &&
        (!form.name.trim() || !form.startup.trim() || Object.keys(dockerRowsToMap(form.dockerRows)).length === 0);
    const blockedReason = hasJsonError
        ? m['admin.nests.egg.json.blocked']()
        : missingEssentials
          ? m['admin.nests.egg.createValidation']()
          : null;

    const sectionDirty = (id: SectionId) =>
        id === 'variables' ? varsDirty : SECTION_KEYS[id].some(k => dirtyKeys.includes(k));

    // ── Leave guard + Ctrl/Cmd+S ─────────────────────────────────────────────
    // Refs, not state: the blocker runs outside render. `leaving` lets our own
    // post-create / post-delete navigation through.
    const dirtyRef = useRef(false);
    const leavingRef = useRef(false);
    useEffect(() => {
        dirtyRef.current = dirty;
    });

    const blocker = useBlocker(
        ({ currentLocation, nextLocation }) =>
            !leavingRef.current && dirtyRef.current && currentLocation.pathname !== nextLocation.pathname,
    );

    useEffect(() => {
        if (!dirty) return;
        const onBeforeUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onBeforeUnload);
        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);

    const formRef = useRef<HTMLFormElement>(null);
    useEffect(() => {
        const onKeyDown = (e: KeyboardEvent) => {
            if (!(e.metaKey || e.ctrlKey) || e.altKey || e.key.toLowerCase() !== 's') return;
            e.preventDefault();
            // A variable/export/delete dialog owns the keyboard while it's open.
            if (e.repeat || document.querySelector('[role="dialog"]')) return;
            formRef.current?.requestSubmit();
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    // ── Persistence ──────────────────────────────────────────────────────────

    const invalidateLists = () => {
        queryClient.invalidateQueries({ queryKey: ['admin', 'nests'] });
        queryClient.invalidateQueries({ queryKey: ['admin', 'nest-eggs', Number(nestId)] });
    };

    const save = async () => {
        if (!egg) return;
        const snapshot = form;
        const keys = dirtyKeys;
        setSaving(true);
        try {
            if (keys.length > 0) {
                // Only the fields that changed, so nothing stale is rewritten.
                const payload = fullPayload(snapshot);
                const subset: Partial<EggPayload> = {};
                for (const k of keys) {
                    const pk = PAYLOAD_KEY[k];
                    (subset as Record<string, unknown>)[pk] = payload[pk];
                }
                await updateEgg(egg.id, subset);
                setBaseline(prev => {
                    const next = { ...prev };
                    for (const k of keys) (next as Record<string, unknown>)[k] = snapshot[k];
                    return next;
                });
            }
            if (varsDirty) {
                const fresh = await updateEggVariables(egg.id, varRows);
                setVarRows(fresh);
                setVarBaseline(fresh);
            }
            invalidateLists();
            queryClient.invalidateQueries({ queryKey: ['admin', 'egg', eggId] });
            push({ type: 'success', message: m['admin.nests.egg.saved']() });
        } catch (err) {
            push({ type: 'error', message: firstError(err) ?? m['common.states.genericError']() });
        } finally {
            setSaving(false);
        }
    };

    const create = async () => {
        if (!nestId) return;
        setSaving(true);
        try {
            const created = await createEgg(Number(nestId), fullPayload(form));
            invalidateLists();
            push({ type: 'success', message: m['admin.nests.egg.created']() });
            leavingRef.current = true;
            // Straight to Variables: it's the one section a new egg can't fill in yet.
            navigate(`/admin/nests/${nestId}/eggs/${created.id}?section=variables`);
        } catch (err) {
            push({ type: 'error', message: firstError(err) ?? m['common.states.genericError']() });
        } finally {
            setSaving(false);
        }
    };

    const onSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (saving || !dirty || blockedReason) return;
        void (isCreate ? create() : save());
    };

    const discard = () => {
        setForm(baseline);
        setVarRows(varBaseline);
    };

    const remove = async () => {
        if (!egg) return;
        setDeleting(true);
        try {
            await deleteEgg(egg.id);
            invalidateLists();
            push({ type: 'success', message: m['admin.nests.egg.deleted']() });
            leavingRef.current = true;
            navigate(`/admin/nests/${nestId}`);
        } catch (err) {
            push({ type: 'error', message: firstError(err) ?? m['common.states.genericError']() });
            setDeleting(false);
            setShowDelete(false);
        }
    };

    if (!isCreate && isLoading) {
        return (
            <div className="flex items-center justify-center py-24">
                <Spinner className="h-7 w-7" />
            </div>
        );
    }

    if (!isCreate && (isError || !egg)) {
        return (
            <div className="rounded-md border border-[var(--color-danger)]/40 bg-[var(--color-danger)]/10 px-5 py-4 text-sm text-[var(--color-danger)]">
                {m['admin.nests.egg.loadError']()}
            </div>
        );
    }

    return (
        <form ref={formRef} onSubmit={onSubmit} className="flex flex-col gap-5">
            {/* ── Header ── */}
            <div>
                <Link
                    to={`/admin/nests/${nestId}`}
                    className="inline-flex items-center gap-1.5 text-xs text-[var(--color-ink-faint)] transition-colors hover:text-[var(--color-ink)]"
                >
                    <ArrowLeft className="h-3.5 w-3.5" />
                    {m['admin.nests.egg.back']()}
                </Link>
                <div className="mt-1 flex flex-wrap items-start gap-3">
                    <div className="min-w-0 flex-1">
                        <h1 className="truncate text-xl font-semibold text-[var(--color-ink)]">
                            {isCreate ? m['ui.labels.newEgg']() : baseline.name}
                        </h1>
                        {egg ? (
                            <EggMeta egg={egg} onVariables={() => setSection('variables')} />
                        ) : (
                            <p className="mt-0.5 text-sm text-[var(--color-ink-faint)]">{m['admin.nests.egg.createSubtitle']()}</p>
                        )}
                    </div>
                    {egg && (
                        <div className="flex items-center gap-2">
                            <Button type="button" variant="outline" size="sm" onClick={() => setShowExport(true)}>
                                <Download className="h-4 w-4" /> {m['admin.nests.egg.exportAction']()}
                            </Button>
                            <Dropdown.Root>
                                <Dropdown.Trigger
                                    type="button"
                                    aria-label={m['ui.labels.moreActions']()}
                                    title={m['ui.labels.moreActions']()}
                                    className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--color-border-strong)] text-[var(--color-ink-muted)] transition-colors hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)] focus:outline-none"
                                >
                                    <MoreHorizontal className="h-4 w-4" />
                                </Dropdown.Trigger>
                                <Dropdown.Portal>
                                    <Dropdown.Content
                                        align="end"
                                        sideOffset={4}
                                        className="z-[60] w-56 overflow-hidden rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface)] p-1 shadow-xl shadow-black/30"
                                    >
                                        <Dropdown.Item
                                            disabled={egg.serverCount > 0}
                                            onSelect={() => setShowDelete(true)}
                                            className="flex cursor-pointer select-none flex-col items-start gap-0.5 rounded-lg px-3 py-2 text-sm text-[var(--color-danger)] outline-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50 data-[highlighted]:bg-[var(--color-surface-2)]"
                                        >
                                            <span className="flex items-center gap-2">
                                                <Trash2 className="h-3.5 w-3.5" /> {m['admin.nests.egg.delete.title']()}
                                            </span>
                                            {egg.serverCount > 0 && (
                                                <span className="text-xs text-[var(--color-ink-faint)]">
                                                    {m['admin.nests.egg.delete.inUse']({ count: egg.serverCount })}
                                                </span>
                                            )}
                                        </Dropdown.Item>
                                    </Dropdown.Content>
                                </Dropdown.Portal>
                            </Dropdown.Root>
                        </div>
                    )}
                </div>
            </div>

            {/* ── Section nav + body ── */}
            <div className="grid gap-5 lg:grid-cols-[12.5rem_minmax(0,1fr)]">
                <nav
                    aria-label={m['admin.nests.egg.nav']()}
                    className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1 lg:sticky lg:top-4 lg:flex-col lg:self-start lg:overflow-visible lg:pb-0"
                >
                    {SECTIONS.map(s => {
                        const active = section === s.id;
                        const error = s.id === 'configuration' && hasJsonError;
                        const changed = sectionDirty(s.id);
                        return (
                            <button
                                key={s.id}
                                type="button"
                                onClick={() => setSection(s.id)}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors',
                                    active
                                        ? 'bg-[var(--color-surface-2)] font-medium text-[var(--color-ink)]'
                                        : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-2)]/60 hover:text-[var(--color-ink)]',
                                )}
                            >
                                <s.icon className={cn('h-4 w-4 shrink-0', active ? 'text-[var(--brand-bright)]' : 'text-[var(--color-ink-faint)]')} />
                                <span className="flex-1 whitespace-nowrap text-left">{s.label()}</span>
                                {s.id === 'variables' && !isCreate && (
                                    <span className="rounded bg-[var(--color-surface-2)] px-1.5 text-[11px] text-[var(--color-ink-faint)]">
                                        {varRows.length}
                                    </span>
                                )}
                                {error ? (
                                    <AlertTriangle
                                        className="h-3.5 w-3.5 shrink-0 text-[var(--color-danger)]"
                                        aria-label={m['admin.nests.egg.json.invalid']()}
                                    />
                                ) : (
                                    changed && (
                                        <span
                                            className="h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--color-warning)]"
                                            aria-label={m['common.editor.unsaved']()}
                                        />
                                    )
                                )}
                            </button>
                        );
                    })}
                    <p className="mt-3 hidden px-3 text-[11px] text-[var(--color-ink-faint)] lg:block">
                        {m['admin.nests.egg.saveHint']({ shortcut: IS_MAC ? '⌘S' : 'Ctrl+S' })}
                    </p>
                </nav>

                <div className="flex min-w-0 flex-col gap-5">
                    {section === 'general' && (
                        <SectionCard
                            icon={Info}
                            title={m['ui.labels.general']()}
                            desc={m['admin.nests.egg.section.generalDesc']()}
                        >
                            <FieldGrid>
                                <FieldRow label={m['ui.labels.name']()}>
                                    <Input value={form.name} onChange={e => patch({ name: e.currentTarget.value })} />
                                </FieldRow>
                                <FieldRow
                                    label={m['admin.nests.egg.about.updateUrl']()}
                                    desc={m['admin.nests.egg.about.updateUrlHint']()}
                                >
                                    <Input
                                        placeholder="https://"
                                        value={form.updateUrl}
                                        onChange={e => patch({ updateUrl: e.currentTarget.value })}
                                    />
                                </FieldRow>
                                <FieldRow label={m['common.labels.description']()} wide>
                                    <Textarea
                                        rows={4}
                                        value={form.description}
                                        onChange={e => patch({ description: e.currentTarget.value })}
                                    />
                                </FieldRow>
                            </FieldGrid>
                        </SectionCard>
                    )}

                    {section === 'startup' && (
                        <>
                            <SectionCard
                                icon={SquareTerminal}
                                title={m['ui.labels.startup']()}
                                desc={m['admin.nests.egg.section.startupDesc']()}
                            >
                                <FieldGrid>
                                    <FieldRow label={m['ui.labels.startupCommand']()} wide>
                                        <StartupCommandField
                                            value={form.startup}
                                            onChange={startup => patch({ startup })}
                                            variables={varRows.map(v => v.environmentVariable).filter(Boolean)}
                                        />
                                    </FieldRow>
                                    <FieldRow
                                        label={m['admin.nests.egg.about.stopCommand']()}
                                        desc={m['admin.nests.egg.about.stopHint']()}
                                    >
                                        <Input
                                            className="font-mono"
                                            value={form.configStop}
                                            onChange={e => patch({ configStop: e.currentTarget.value })}
                                        />
                                    </FieldRow>
                                </FieldGrid>
                            </SectionCard>
                            <SectionCard
                                icon={Container}
                                title={m['admin.nests.egg.tabs.docker']()}
                                desc={m['admin.nests.egg.section.dockerDesc']()}
                            >
                                <DockerImageManager rows={form.dockerRows} onChange={rows => patch({ dockerRows: rows })} />
                            </SectionCard>
                        </>
                    )}

                    {section === 'variables' && (
                        <VariablesSection
                            eggId={egg ? egg.id : null}
                            rows={varRows}
                            baseline={varBaseline}
                            onRowsChange={setVarRows}
                            onCreated={v => {
                                setVarRows(prev => [...prev, v]);
                                setVarBaseline(prev => [...prev, v]);
                                invalidateLists();
                            }}
                            onDeleted={id => {
                                setVarRows(prev => prev.filter(v => v.id !== id));
                                setVarBaseline(prev => prev.filter(v => v.id !== id));
                                invalidateLists();
                            }}
                        />
                    )}

                    {section === 'configuration' && (
                        <>
                            <SectionCard
                                icon={FileCog}
                                title={m['admin.nests.egg.section.detectionTitle']()}
                                desc={m['admin.nests.egg.section.detectionDesc']()}
                            >
                                <CodeEditor
                                    value={form.configStartup}
                                    onChange={v => patch({ configStartup: v })}
                                    language="JSON"
                                    height="12rem"
                                    error={startupJsonError}
                                />
                            </SectionCard>
                            <SectionCard
                                icon={FileCog}
                                title={m['admin.nests.egg.about.configFiles']()}
                                desc={m['admin.nests.egg.section.configFilesDesc']()}
                            >
                                <CodeEditor
                                    value={form.configFiles}
                                    onChange={v => patch({ configFiles: v })}
                                    language="JSON"
                                    height="24rem"
                                    error={configFilesJsonError}
                                />
                            </SectionCard>
                        </>
                    )}

                    {section === 'install' && (
                        <>
                            <SectionCard
                                icon={ScrollText}
                                title={m['ui.actions.installScript']()}
                                desc={m['admin.nests.egg.section.installDesc']()}
                            >
                                <CodeEditor
                                    value={form.scriptInstall}
                                    onChange={v => patch({ scriptInstall: v })}
                                    language="Shell"
                                    height="calc(100vh - 26rem)"
                                />
                            </SectionCard>
                            <SectionCard
                                icon={Container}
                                title={m['admin.nests.egg.section.installRuntime']()}
                                desc={m['admin.nests.egg.section.installRuntimeDesc']()}
                            >
                                <FieldGrid>
                                    <FieldRow
                                        label={m['admin.nests.egg.install.container']()}
                                        desc={m['admin.nests.egg.install.containerHint']()}
                                    >
                                        <Input
                                            className="font-mono"
                                            value={form.scriptContainer}
                                            onChange={e => patch({ scriptContainer: e.currentTarget.value })}
                                        />
                                    </FieldRow>
                                    <FieldRow
                                        label={m['admin.nests.egg.install.entrypoint']()}
                                        desc={m['admin.nests.egg.install.entrypointHint']()}
                                    >
                                        <Input
                                            className="font-mono"
                                            value={form.scriptEntry}
                                            onChange={e => patch({ scriptEntry: e.currentTarget.value })}
                                        />
                                    </FieldRow>
                                </FieldGrid>
                                <ToggleGroup>
                                    <ToggleRow
                                        label={m['admin.nests.egg.advanced.privileged']()}
                                        desc={m['admin.nests.egg.advanced.privilegedDesc']()}
                                        checked={form.scriptIsPrivileged}
                                        onChange={v => patch({ scriptIsPrivileged: v })}
                                    />
                                </ToggleGroup>
                            </SectionCard>
                        </>
                    )}

                    {section === 'advanced' && (
                        <SectionCard
                            icon={Settings2}
                            title={m['ui.labels.advanced']()}
                            desc={m['admin.nests.egg.section.advancedDesc']()}
                        >
                            <ToggleGroup>
                                {IMPLEMENTED_FEATURES.map(feature => (
                                    <ToggleRow
                                        key={feature}
                                        label={td(`admin.nests.egg.advanced.feature.${feature}.name`, feature)}
                                        desc={td(`admin.nests.egg.advanced.feature.${feature}.desc`, '')}
                                        checked={form.features.includes(feature)}
                                        onChange={on =>
                                            patch({
                                                features: on
                                                    ? [...form.features, feature]
                                                    : form.features.filter(f => f !== feature),
                                            })
                                        }
                                    />
                                ))}
                                <ToggleRow
                                    label={m['admin.nests.egg.advanced.forceOutgoingIp']()}
                                    desc={m['admin.nests.egg.advanced.forceOutgoingIpDesc']()}
                                    checked={form.forceOutgoingIp}
                                    onChange={v => patch({ forceOutgoingIp: v })}
                                />
                            </ToggleGroup>
                            <FieldRow
                                label={m['admin.nests.egg.advanced.fileDenylist']()}
                                desc={m['admin.nests.egg.advanced.fileDenylistHint']()}
                            >
                                <Textarea
                                    rows={6}
                                    className="font-mono"
                                    value={form.fileDenylistText}
                                    onChange={e => patch({ fileDenylistText: e.currentTarget.value })}
                                />
                            </FieldRow>
                        </SectionCard>
                    )}
                </div>
            </div>

            <SaveBar
                dirty={dirty}
                saving={saving}
                onDiscard={discard}
                blockedReason={blockedReason}
                labels={isCreate ? { save: m['admin.nests.egg.createAction']() } : undefined}
            />

            {showExport && egg && <ExportEggModal eggId={egg.id} onClose={() => setShowExport(false)} />}

            <ConfirmDialog
                open={showDelete}
                onClose={() => setShowDelete(false)}
                title={m['admin.nests.egg.delete.title']()}
                body={m['admin.nests.egg.delete.body']()}
                confirmLabel={m['admin.nests.egg.delete.confirm']()}
                cancelLabel={m['common.actions.cancel']()}
                busy={deleting}
                onConfirm={remove}
            />

            <Modal
                open={blocker.state === 'blocked'}
                onClose={() => blocker.reset?.()}
                title={m['common.editor.leaveTitle']()}
                size="sm"
                footer={
                    <>
                        <Button type="button" variant="ghost" size="sm" onClick={() => blocker.reset?.()}>
                            {m['common.editor.leaveStay']()}
                        </Button>
                        <Button type="button" variant="danger" size="sm" onClick={() => blocker.proceed?.()}>
                            {m['common.actions.discard']()}
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-[var(--color-ink-muted)]">{m['admin.nests.egg.leaveBody']()}</p>
            </Modal>
        </form>
    );
}

// ─── Header meta line ─────────────────────────────────────────────────────────

// The variable count opens its section; it used to be a number to go and find.
// Servers have no tab (and the servers list can't filter by egg yet), so that
// count stays plain text.
function EggMeta({ egg, onVariables }: { egg: AdminEggDetail; onVariables: () => void }) {
    const [copied, setCopied] = useState(false);
    const copy = () =>
        void navigator.clipboard?.writeText(egg.uuid).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });

    return (
        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[var(--color-ink-faint)]">
            <span>
                {m['ui.labels.id']()} {egg.id}
            </span>
            <span aria-hidden>·</span>
            <span className="truncate">{egg.author}</span>
            <span aria-hidden>·</span>
            <span>{m['admin.nests.egg.meta.servers']({ count: egg.serverCount })}</span>
            <span aria-hidden>·</span>
            <button
                type="button"
                onClick={onVariables}
                className="rounded px-1 underline-offset-2 transition-colors hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)] hover:underline"
            >
                {m['admin.nests.egg.meta.variables']({ count: egg.variables.length })}
            </button>
            <span aria-hidden>·</span>
            <button
                type="button"
                onClick={copy}
                title={m['admin.nests.egg.copyUuid']()}
                className="inline-flex min-w-0 items-center gap-1 rounded px-1 font-mono transition-colors hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)]"
            >
                <span className="truncate">{egg.uuid}</span>
                {copied ? (
                    <Check className="h-3 w-3 shrink-0 text-[var(--color-accent)]" />
                ) : (
                    <Copy className="h-3 w-3 shrink-0" />
                )}
            </button>
        </div>
    );
}

// ─── Startup command + variable chips ─────────────────────────────────────────

function StartupCommandField({
    value,
    onChange,
    variables,
}: {
    value: string;
    onChange: (next: string) => void;
    variables: string[];
}) {
    const inputRef = useRef<HTMLInputElement>(null);

    const used = new Set([...value.matchAll(/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g)].map(match => match[1] ?? ''));
    const known = new Set([...ENVIRONMENT_VARIABLES, ...variables]);
    const unknown = [...used].filter(name => name && !known.has(name));

    // Insert at the caret (or replace the selection), then put the caret after it.
    const insert = (name: string) => {
        const token = `{{${name}}}`;
        const el = inputRef.current;
        const start = el?.selectionStart ?? value.length;
        const end = el?.selectionEnd ?? value.length;
        onChange(value.slice(0, start) + token + value.slice(end));
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(start + token.length, start + token.length);
        });
    };

    const chip = (name: string, own: boolean) => (
        <button
            key={name}
            type="button"
            onClick={() => insert(name)}
            className={cn(
                'rounded border px-1.5 py-0.5 font-mono text-[11px] transition-colors',
                own
                    ? 'border-[var(--brand)]/40 bg-[var(--brand-soft)] text-[var(--brand-bright)] hover:border-[var(--brand)]'
                    : 'border-[var(--color-border-strong)] bg-[var(--color-surface-2)] text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]',
                used.has(name) && 'opacity-60',
            )}
        >
            {name}
        </button>
    );

    return (
        <div className="flex flex-col gap-2">
            <Input
                ref={inputRef}
                className="font-mono"
                spellCheck={false}
                value={value}
                onChange={e => onChange(e.currentTarget.value)}
            />
            <div className="flex flex-wrap items-center gap-1.5">
                <span className="mr-0.5 text-xs text-[var(--color-ink-faint)]">{m['admin.nests.egg.startup.insert']()}</span>
                {variables.map(name => chip(name, true))}
                {PANEL_VARIABLES.map(name => chip(name, false))}
            </div>
            {unknown.length > 0 && (
                <p className="flex items-center gap-1.5 text-xs text-[var(--color-warning)]">
                    <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                    {m['admin.nests.egg.startup.unknown']({ names: unknown.map(n => `{{${n}}}`).join(', ') })}
                </p>
            )}
        </div>
    );
}
