import { useEffect, useMemo, useState } from 'react';
import CodeMirror from '@uiw/react-codemirror';
import { type Extension } from '@codemirror/state';
import { AlertTriangle } from 'lucide-react';
import { cn } from '@/lib/cn';
import { editorTheme } from '@/lib/editorTheme';
import { loadRequiredEditorLanguage } from '@/lib/editorLanguages';

// CodeMirror wrapper for the egg editor's JSON and shell blocks and the egg
// import/export modals. Shares the file manager's theme so syntax colours
// follow the palette. `error` renders a footer strip under the editor — the
// egg editor uses it for live JSON validation.
export function CodeEditor({
    value,
    onChange,
    language,
    height = '16rem',
    readOnly = false,
    error,
}: {
    value: string;
    onChange?: (next: string) => void;
    language: 'JSON' | 'Shell';
    height?: string;
    readOnly?: boolean;
    error?: string | null;
}) {
    const [langExt, setLangExt] = useState<Extension | null>(null);

    useEffect(() => {
        let active = true;
        void loadRequiredEditorLanguage(language).then(support => {
            if (active) setLangExt(support);
        });
        return () => {
            active = false;
        };
    }, [language]);

    const extensions = useMemo(
        () => (langExt ? [editorTheme, langExt] : [editorTheme]),
        [langExt],
    );

    return (
        <div
            className={cn(
                'overflow-hidden rounded-[var(--radius-card)] border',
                error ? 'border-[var(--color-danger)]/60' : 'border-[var(--color-border-strong)]',
            )}
        >
            <CodeMirror
                value={value}
                height={height}
                theme="none"
                extensions={extensions}
                editable={!readOnly}
                onChange={onChange}
                basicSetup={{ foldGutter: true, highlightActiveLine: !readOnly }}
            />
            {error && (
                <p
                    aria-live="polite"
                    className="flex items-center gap-2 border-t border-[var(--color-danger)]/40 bg-[var(--color-danger)]/10 px-3 py-1.5 font-mono text-xs text-[var(--color-danger)]"
                >
                    <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                    {error}
                </p>
            )}
        </div>
    );
}
