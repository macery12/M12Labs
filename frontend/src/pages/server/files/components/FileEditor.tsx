import { useEffect, useMemo, useRef, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import CodeMirror from '@uiw/react-codemirror';
import { EditorView } from '@codemirror/view';
import type { Extension } from '@codemirror/state';
import { ArrowLeft, Save } from 'lucide-react';
import { m } from '@/i18n/messages';
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
import { dirname, encodePathSegments } from '../paths';
import { EDITOR_LANGUAGES, loadEditorLanguage, matchEditorLanguage } from '@/lib/editorLanguages';

// Editor chrome themed against the V2 tokens so CodeMirror follows light/dark.
const themeExtension = EditorView.theme({
    '&': {
        backgroundColor: 'var(--color-surface-2)',
        color: 'var(--color-ink)',
        fontSize: '13px',
        borderRadius: 'var(--radius-card)',
    },
    '.cm-content': { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' },
    '.cm-gutters': {
        backgroundColor: 'var(--color-surface)',
        color: 'var(--color-ink-faint)',
        border: 'none',
    },
    '.cm-activeLine': { backgroundColor: 'var(--color-surface)' },
    '.cm-activeLineGutter': { backgroundColor: 'var(--color-surface)' },
    '.cm-cursor': { borderLeftColor: 'var(--color-ink)' },
    '&.cm-focused .cm-selectionBackground, .cm-selectionBackground': {
        backgroundColor: 'var(--brand-soft)',
    },
    '.cm-scroller': { overflow: 'auto' },
});

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

    const extensions = useMemo(() => (langExt ? [themeExtension, langExt] : [themeExtension]), [langExt]);

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
            {/* ── Header ── */}
            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Button variant="ghost" size="sm" onClick={() => navigate(backHref)}>
                    <ArrowLeft className="h-4 w-4" />
                    {m['server.files.editor.back']()}
                </Button>
                <div className="min-w-0">
                    <p className="truncate font-mono text-sm text-[var(--color-ink)]">
                        {action === 'new' ? m['server.files.editor.newFile']() : filename}
                    </p>
                    {readonly && (
                        <p className="text-xs text-[var(--color-warning)]">{m['server.files.editor.archiveReadonly']()}</p>
                    )}
                </div>
                <div className="ml-auto flex items-center gap-2">
                    <div className="w-44">
                        <Select
                            value={langName}
                            onChange={loadLanguage}
                            options={langOptions}
                            placeholder={m['server.files.editor.language']()}
                        />
                    </div>
                    {(action === 'new' ? canCreate : canUpdate) && (
                        <Button size="sm" onClick={onSave} disabled={saving || readonly || (action === 'edit' && !dirty)}>
                            {saving ? <Spinner className="h-4 w-4" /> : <Save className="h-4 w-4" />}
                            {action === 'new' ? m['server.files.editor.create']() : m['common.actions.save']()}
                        </Button>
                    )}
                </div>
            </div>

            {/* ── .pteroignore hint (parity) ── */}
            {filename.endsWith('.pteroignore') && (
                <div className="mb-4 rounded-[var(--radius-card)] border-l-2 border-[var(--color-accent)] bg-[var(--color-surface-2)] p-3">
                    <p className="text-xs text-[var(--color-ink-muted)]">{m['server.files.editor.pteroignore']()}</p>
                </div>
            )}

            <div className="relative overflow-hidden rounded-[var(--radius-card)] border border-[var(--color-border-strong)]">
                {loading && (
                    <div className="absolute inset-0 z-10 flex items-center justify-center bg-[var(--color-surface)]/70">
                        <Spinner className="h-8 w-8" />
                    </div>
                )}
                <CodeMirror
                    value={content}
                    height="calc(100vh - 18rem)"
                    theme="none"
                    extensions={extensions}
                    editable={!readonly}
                    onChange={setContent}
                    basicSetup={{ foldGutter: true, highlightActiveLine: true }}
                />
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
        </div>
    );
}
