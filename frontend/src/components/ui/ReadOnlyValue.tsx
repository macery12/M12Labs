import { useState } from 'react';
import { Check, Copy } from 'lucide-react';
import { m } from '@/i18n/messages';
import { cn } from '@/lib/cn';

// A labelled value the user can read but not change: UUIDs, limits set by an
// admin, a counter the system advances. These used to be drawn as disabled or
// bordered inputs, which read as "editable, but broken". Plain text on the
// same row height as an input keeps it aligned inside a form grid without
// looking like one. `copy` adds a visible copy button for identifiers.
export interface ReadOnlyValueProps {
    label: string;
    desc?: string;
    mono?: boolean;
    /** The text to put on the clipboard. Omit for values nobody pastes. */
    copy?: string;
    children: React.ReactNode;
}

export function ReadOnlyValue({ label, desc, mono, copy, children }: ReadOnlyValueProps) {
    return (
        <div className="flex min-w-0 flex-col gap-1.5">
            <span className="text-sm font-medium text-[var(--color-ink-muted)]">{label}</span>
            <div className="flex min-h-10 min-w-0 items-center gap-2">
                <span
                    className={cn('min-w-0 truncate text-sm text-[var(--color-ink)]', mono && 'font-mono text-xs')}
                    title={typeof children === 'string' ? children : undefined}
                >
                    {children}
                </span>
                {copy !== undefined && <CopyButton value={copy} />}
            </div>
            {desc && <span className="text-xs text-[var(--color-ink-faint)]">{desc}</span>}
        </div>
    );
}

function CopyButton({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    const copy = () =>
        navigator.clipboard?.writeText(value).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });

    const label = copied ? m['common.states.copied']() : m['common.actions.copy']();

    return (
        <button
            type="button"
            onClick={copy}
            title={label}
            aria-label={label}
            className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-[var(--color-ink-faint)] transition-colors hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)]"
        >
            {copied ? <Check className="h-3.5 w-3.5 text-[var(--color-accent)]" /> : <Copy className="h-3.5 w-3.5" />}
        </button>
    );
}
