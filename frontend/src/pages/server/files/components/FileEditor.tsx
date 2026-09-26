import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useBlocker, useLocation, useNavigate, useParams } from 'react-router-dom';
import CodeMirror from '@uiw/react-codemirror';
import { EditorView, type ViewUpdate } from '@codemirror/view';
import type { Extension } from '@codemirror/state';
import { ArrowLeft, ChevronRight, House, Lock, Save, WrapText } from 'lucide-react';
import { m } from '@/i18n/messages';
import { cn } from '@/lib/cn';
import { useServer } from '@/components/server/ServerContext';
import { useWideContent } from '@/components/shell/shellLayout';
import { can } from '@/lib/can';
import { firstError } from '@/lib/apiError';
import { useFlashes } from '@/state/flashes';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { fileExists, getFileContents, saveFileContents } from '@/api/files';
import { validateFileName } from './Modals';
import { editorTheme } from './editorTheme';
import { dirname, encodePathSegments } from '../paths';
import { EDITOR_LANGUAGES, loadEditorLanguage, matchEditorLanguage } from '@/lib/editorLanguages';

const IS_MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent);
const SAVE_SHORTCUT = IS_MAC ? '⌘S' : 'Ctrl+S';

// Per-viewer preference only; storage can be absent (private mode) or throw.
const WRAP_KEY = 'files.editor.wrap';
function readWrap(): boolean {
    try {
        return window.localStorage.getItem(WRAP_KEY) === '1';
    } catch {
        return false;
    }
}
function writeWrap(on: boolean) {
    try {
        window.localStorage.setItem(WRAP_KEY, on ? '1' : '0');
    } catch {
        /* preference just won't persist */
    }
}

const ARCHIVE_SEGMENT =
    /\.(zip|7z|rar|tar|tgz|txz|tzst|tlz4|tbz2|gz|xz|zst|lz4|bz2|ddup)$/i;

// A file that lives *inside* an archive path can't be written back.
function isInsideArchive(path: string): boolean {
    return path
        .split('/')
        .filter(Boolean)
        .some(seg => ARCHIVE_SEGMENT.test(seg));
}

export default function FileEditor({ action }: { action: 'edit' | 'new' }) {
    useWideContent();
    const server = useServer();
    const { uuid, id, permissions: held } = server;
    const navigate = useNavigate();
    const push = useFlashes(s => s.push);
    const params = useParams<{ '*': string }>();
    const { hash } = useLocation();

    const filename = action === 'edit' ? decodeURIComponent(params['*'] ?? '') : '';
    const directory =
        action === 'new' ? decodeURIComponent(hash.slice(1) || '/') : dirname(filename);
    const readonly = action === 'edit' && isInsideArchive(filename);
    const canUpdate = can(held, 'file.update');
    const canCreate = can(held, 'file.create');

    const [content, setContent] = useState('');
    const [originalContent, setOriginalContent] = useState('');
    const originalRef = useRef('');
    const [loading, setLoading] = useState(action === 'edit');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);
    const [langName, setLangName] = useState('');
    const [langExt, setLangExt] = useState<Extension | null>(null);
    const [showNameModal, setShowNameModal] = useState(false);
    const [newName, setNewName] = useState('');
    const [nameError, setNameError] = useState<string | undefined>();
    // Set once we know the typed name already exists, so the create step can ask
    // before replacing it — the daemon's write has no exclusive-create mode.
    const [overwriteTarget, setOverwriteTarget] = useState<{ path: string; name: string } | null>(null);
    const [checkingName, setCheckingName] = useState(false);
    const [wrap, setWrap] = useState(readWrap);
    const [cursor, setCursor] = useState({ line: 1, col: 1, lines: 1 });
    const contentRef = useRef('');

    // Load existing content.
    useEffect(() => {
        if (action !== 'edit' || !filename) return;
        const controller = new AbortController();
        // eslint-disable-next-line react-hooks/set-state-in-effect -- intentional effect: syncs state to prop/query/filter changes
        setLoading(true);
        setError(null);
        getFileContents(uuid, filename, { signal: controller.signal })
            .then(text => {
                setContent(text);
                setOriginalContent(text);
                originalRef.current = text;
                contentRef.current = text;
            })
            .catch(e => {
                if (!controller.signal.aborted) setError(firstError(e) ?? m['common.states.genericError']());
            })
            .finally(() => setLoading(false));
        return () => controller.abort();
    }, [uuid, filename, action]);

    // Auto-detect language from the filename.
    useEffect(() => {
        if (!filename) return;
        const match = matchEditorLanguage(filename);
        let active = true;
        if (match) {
            // eslint-disable-next-line react-hooks/set-state-in-effect -- intentional effect: syncs state to prop/query/filter changes
            setLangName(match.name);
            match.load().then(extension => {
                if (active) setLangExt(extension);
            });
        }
        return () => {
            active = false;
        };
    }, [filename]);

    const loadLanguage = (name: string) => {
        setLangName(name);
        void loadEditorLanguage(name).then(setLangExt);
    };

    const langOptions = useMemo(
        () => [
            { value: '', label: m['server.files.editor.plainText']() },
            ...EDITOR_LANGUAGES.map(language => ({ value: language.name, label: language.name })),
        ],
        [],
    );

    const extensions = useMemo(() => {
        const list: Extension[] = [editorTheme];
        if (wrap) list.push(EditorView.lineWrapping);
        if (langExt) list.push(langExt);
        return list;
    }, [langExt, wrap]);

    const onChange = useCallback((value: string) => {
        contentRef.current = value;
        setContent(value);
    }, []);

    const onUpdate = useCallback((update: ViewUpdate) => {
        if (!update.selectionSet && !update.docChanged) return;
        const { state } = update;
        const head = state.selection.main.head;
        const line = state.doc.lineAt(head);
        const next = { line: line.number, col: head - line.from + 1, lines: state.doc.lines };
        setCursor(prev =>
            prev.line === next.line && prev.col === next.col && prev.lines === next.lines ? prev : next,
        );
    }, []);

    const toggleWrap = () => {
        setWrap(on => {
            writeWrap(!on);
            return !on;
        });
    };

    const doSave = async (targetName: string, isNew: boolean) => {
        setSaving(true);
        try {
            // A brand-new file has no original to compare against, so it must go
            // through the plain write endpoint. Passing '' here instead routed it
            // to write-with-diff, whose compare-and-swap reads the live file —
            // which does not exist yet — and whose `original_content` rule the
            // empty string could never satisfy ("The original content must be a
            // string", after ConvertEmptyStringsToNull turned it into null).
            await saveFileContents(uuid, targetName, content, isNew ? undefined : originalRef.current);
            originalRef.current = content;
            setOriginalContent(content);
            push({ type: 'success', message: m['server.files.editor.saved']() });
            if (isNew) navigate(`/server/${id}/files/edit/${encodePathSegments(targetName)}`);
        } catch (e) {
            push({ type: 'error', message: firstError(e) ?? m['common.states.genericError']() });
        } finally {
            setSaving(false);
        }
    };

    const onSave = () => {
        if (readonly) return;
        if (action === 'new') setShowNameModal(true);
        else void doSave(filename, false);
    };

    const confirmNewName = async () => {
        const trimmed = newName.trim();
        // Same rules as the new-directory and rename dialogs, which this modal
        // never applied — an illegal name used to fail opaquely at the daemon.
        const err = validateFileName(trimmed, true);
        if (err) {
            setNameError(err);
            return;
        }

        const target = `${directory.replace(/\/+$/, '')}/${trimmed}`.replace(/\/{2,}/g, '/');

        setCheckingName(true);
        const exists = await fileExists(uuid, dirname(target), trimmed.split('/').pop() ?? trimmed);
        setCheckingName(false);

        setShowNameModal(false);

        if (exists) {
            setOverwriteTarget({ path: target, name: trimmed });
            return;
        }

        void doSave(target, true);
    };

    const dirty = content !== originalContent;
    const backHref = `/server/${id}/files#${encodePathSegments(directory)}`;
    const canWrite = action === 'new' ? canCreate : canUpdate;
    const canSave = canWrite && !saving && !readonly && (action === 'new' || dirty);
    const displayName = action === 'new' ? m['server.files.editor.newFile']() : filename.split('/').pop() || filename;
    const crumbs = directory.split('/').filter(Boolean);

    // Refs, not state: the blocker and the save shortcut run outside render and
    // must see the save that just landed, before React re-renders.
    const isDirty = () => contentRef.current !== originalRef.current;
    const saveRef = useRef<() => void>(() => undefined);
    const modalOpen = showNameModal || overwriteTarget !== null;
    useEffect(() => {
        saveRef.current = () => {
            if (canSave && !modalOpen) onSave();
        };
    });

    useEffect(() => {
        const onKeyDown = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && !e.altKey && e.key.toLowerCase() === 's') {
                // Always swallow it — the browser's "save page" dialog is never
                // what someone in a file editor wants.
                e.preventDefault();
                if (!e.repeat) saveRef.current();
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const blocker = useBlocker(
        ({ currentLocation, nextLocation }) => currentLocation.pathname !== nextLocation.pathname && isDirty(),
    );

    useEffect(() => {
        if (!dirty) return;
        const onBeforeUnload = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', onBeforeUnload);
        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [dirty]);

    const dirHref = (depth: number) =>
        `/server/${id}/files#${encodePathSegments('/' + crumbs.slice(0, depth).join('/'))}`;

    if (error) {
        return (
            <div className="w-full py-10">
                <p className="text-center text-sm text-[var(--color-danger)]">{error}</p>
                <div className="mt-4 flex justify-center">
                    <Button variant="outline" size="sm" onClick={() => navigate(backHref)}>
                        <ArrowLeft className="h-4 w-4" />
                        {m['server.files.editor.back']()}
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="w-full">
            <div className="overflow-hidden rounded-[var(--radius-card)] border border-[var(--color-border-strong)] bg-[var(--color-surface)]">
                {/* ── Toolbar ── */}
                <div className="flex flex-wrap items-center gap-2 border-b border-[var(--color-border-strong)] px-2 py-2 sm:px-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="w-9 shrink-0 px-0"
                        onClick={() => navigate(backHref)}
                        aria-label={m['server.files.editor.back']()}
                        title={m['server.files.editor.back']()}
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Button>

                    <nav className="flex min-w-0 flex-1 items-center gap-1 font-mono text-sm" aria-label={filename || directory}>
                        <button
                            type="button"
                            onClick={() => navigate(dirHref(0))}
                            className="shrink-0 rounded p-1 text-[var(--color-ink-faint)] hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)]"
                            aria-label={m['server.files.editor.root']()}
                            title={m['server.files.editor.root']()}
                        >
                            <House className="h-3.5 w-3.5" />
                        </button>
                        {crumbs.map((part, i) => (
                            <span key={i} className="flex min-w-0 shrink items-center gap-1">
                                <ChevronRight className="h-3.5 w-3.5 shrink-0 text-[var(--color-ink-faint)]" />
                                <button
                                    type="button"
                                    onClick={() => navigate(dirHref(i + 1))}
                                    className="truncate rounded px-1 py-0.5 text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)]"
                                >
                                    {part}
                                </button>
                            </span>
                        ))}
                        <ChevronRight className="h-3.5 w-3.5 shrink-0 text-[var(--color-ink-faint)]" />
                        <span
                            className={cn(
                                'min-w-[4rem] truncate px-1 font-medium text-[var(--color-ink)]',
                                action === 'new' && 'italic text-[var(--color-ink-muted)]',
                            )}
                            title={filename || undefined}
                        >
                            {displayName}
                        </span>
                        {dirty && (
                            <span
                                className="h-2 w-2 shrink-0 rounded-full bg-[var(--color-warning)]"
                                role="status"
                                aria-label={m['server.files.editor.unsaved']()}
                                title={m['server.files.editor.unsaved']()}
                            />
                        )}
                    </nav>

                    <div className="ml-auto flex items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            className={cn('w-9 px-0', wrap && 'bg-[var(--color-surface-2)] text-[var(--color-ink)]')}
                            onClick={toggleWrap}
                            aria-pressed={wrap}
                            aria-label={m['server.files.editor.wrap']()}
                            title={m['server.files.editor.wrap']()}
                        >
                            <WrapText className="h-4 w-4" />
                        </Button>
                        <div className="w-40">
                            <Select
                                value={langName}
                                onChange={loadLanguage}
                                options={langOptions}
                                placeholder={m['server.files.editor.language']()}
                                className="h-9 px-3"
                            />
                        </div>
                        {canWrite && (
                            <Button
                                size="sm"
                                onClick={onSave}
                                disabled={!canSave}
                                title={m['server.files.editor.saveHint']({ shortcut: SAVE_SHORTCUT })}
                            >
                                {saving ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />}
                                {action === 'new' ? m['server.files.editor.create']() : m['common.actions.save']()}
                            </Button>
                        )}
                    </div>
                </div>

                {/* ── Notices ── */}
                {readonly && (
                    <p className="flex items-center gap-2 border-b border-[var(--color-border-strong)] px-4 py-2 text-xs text-[var(--color-warning)]">
                        <Lock className="h-3.5 w-3.5 shrink-0" />
                        {m['server.files.editor.archiveReadonly']()}
                    </p>
                )}
                {filename.endsWith('.pteroignore') && (
                    <p className="border-b border-l-2 border-b-[var(--color-border-strong)] border-l-[var(--color-accent)] px-4 py-2 text-xs text-[var(--color-ink-muted)]">
                        {m['server.files.editor.pteroignore']()}
                    </p>
                )}

                {/* ── Editor ── */}
                <div className="relative">
                    {loading && (
                        <div className="absolute inset-0 z-10 flex items-center justify-center bg-[var(--color-surface)]/70">
                            <Spinner className="h-8 w-8" />
                        </div>
                    )}
                    <CodeMirror
                        value={content}
                        height="calc(100vh - 20rem)"
                        minHeight="18rem"
                        theme="none"
                        extensions={extensions}
                        editable={!readonly}
                        onChange={onChange}
                        onUpdate={onUpdate}
                        basicSetup={{ foldGutter: true, highlightActiveLine: true }}
                    />
                </div>

                {/* ── Status bar ── */}
                <div className="flex items-center gap-3 border-t border-[var(--color-border-strong)] px-4 py-1.5 font-mono text-[11px] text-[var(--color-ink-faint)]">
                    <span>{m['server.files.editor.cursor']({ line: cursor.line, col: cursor.col })}</span>
                    <span aria-hidden>·</span>
                    <span>{m['server.files.editor.lineCount']({ count: cursor.lines })}</span>
                    {readonly && (
                        <>
                            <span aria-hidden>·</span>
                            <span className="text-[var(--color-warning)]">{m['server.files.editor.readonly']()}</span>
                        </>
                    )}
                    <span className="ml-auto truncate">{langName || m['server.files.editor.plainText']()}</span>
                </div>
            </div>

            {/* ── New-file name modal ── */}
            <Modal
                open={showNameModal}
                onClose={() => setShowNameModal(false)}
                title={m['server.files.editor.nameFile']()}
                size="sm"
                footer={
                    <>
                        <Button variant="ghost" size="sm" onClick={() => setShowNameModal(false)}>
                            {m['common.actions.cancel']()}
                        </Button>
                        <Button
                            size="sm"
                            onClick={() => void confirmNewName()}
                            disabled={!newName.trim() || checkingName}
                        >
                            {checkingName && <Spinner className="h-4 w-4" />}
                            {m['common.actions.create']()}
                        </Button>
                    </>
                }
            >
                <label className="mb-1.5 block text-sm text-[var(--color-ink-muted)]">
                    {m['server.files.fileName']()}
                </label>
                <Input
                    autoFocus
                    value={newName}
                    onChange={e => {
                        setNewName(e.target.value);
                        setNameError(undefined);
                    }}
                    onKeyDown={e => e.key === 'Enter' && void confirmNewName()}
                    invalid={!!nameError}
                    placeholder="config.yml"
                />
                {nameError && <p className="mt-1.5 text-xs text-[var(--color-danger)]">{nameError}</p>}
            </Modal>

            {/* ── Overwrite confirmation ── */}
            <Modal
                open={overwriteTarget !== null}
                onClose={() => setOverwriteTarget(null)}
                title={m['server.files.editor.overwriteTitle']()}
                size="sm"
                footer={
                    <>
                        <Button variant="ghost" size="sm" onClick={() => setOverwriteTarget(null)}>
                            {m['common.actions.cancel']()}
                        </Button>
                        <Button
                            variant="danger"
                            size="sm"
                            onClick={() => {
                                const target = overwriteTarget;
                                setOverwriteTarget(null);
                                if (target) void doSave(target.path, true);
                            }}
                        >
                            {m['server.files.editor.overwriteConfirm']()}
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-[var(--color-ink-muted)]">
                    {m['server.files.editor.overwriteBody']({ name: overwriteTarget?.name ?? '' })}
                </p>
            </Modal>

            {/* ── Unsaved-changes guard ── */}
            <Modal
                open={blocker.state === 'blocked'}
                onClose={() => blocker.reset?.()}
                title={m['server.files.editor.leaveTitle']()}
                size="sm"
                footer={
                    <>
                        <Button variant="ghost" size="sm" onClick={() => blocker.reset?.()}>
                            {m['server.files.editor.leaveStay']()}
                        </Button>
                        <Button variant="danger" size="sm" onClick={() => blocker.proceed?.()}>
                            {m['common.actions.discard']()}
                        </Button>
                    </>
                }
            >
                <p className="text-sm text-[var(--color-ink-muted)]">
                    {m['server.files.editor.leaveBody']({ name: displayName })}
                </p>
            </Modal>
        </div>
    );
}
