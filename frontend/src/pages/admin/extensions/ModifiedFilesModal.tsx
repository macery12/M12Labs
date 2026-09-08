import { m } from '@/i18n/messages';
import { FileWarning } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';

const tint = (v: string, pct: number) => `color-mix(in srgb, ${v} ${pct}%, transparent)`;

/**
 * Consent step for discarding local edits to a package's installed files.
 *
 * The panel records a checksum per installed file and refuses to update or
 * remove a package whose files no longer match, so it never silently destroys
 * work an operator meant to keep. The common cause is not tampering but a code
 * formatter run over the panel tree, which rewrites installed package PHP —
 * and without a way through, such a package can be neither updated nor removed.
 */
export function ModifiedFilesModal({
    open,
    extensionName,
    verb,
    paths,
    busy,
    onClose,
    onAcknowledge,
}: {
    open: boolean;
    extensionName: string;
    verb: string;
    paths: string[];
    busy: boolean;
    onClose: () => void;
    onAcknowledge: () => void;
}) {
    return (
        <Modal
            open={open}
            onClose={onClose}
            title={m['extensions.modifiedFiles.title']({ name: extensionName })}
            footer={
                <div className="flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={busy}
                        className="inline-flex h-9 items-center rounded-lg border border-[var(--color-border-strong)] px-3 text-sm text-[var(--color-ink-muted)] transition-colors hover:bg-[var(--color-surface-2)] disabled:opacity-50"
                    >
                        {m['common.actions.cancel']()}
                    </button>
                    <button
                        type="button"
                        onClick={onAcknowledge}
                        disabled={busy}
                        className="inline-flex h-9 items-center rounded-lg bg-[var(--color-danger)] px-3 text-sm font-medium text-white transition-opacity hover:opacity-90 disabled:opacity-50"
                    >
                        {m['extensions.modifiedFiles.discard']()}
                    </button>
                </div>
            }
        >
            <div className="space-y-4 text-sm">
                <div
                    className="flex gap-2 rounded-lg border px-3 py-2.5 text-xs leading-relaxed"
                    style={{
                        background: tint('var(--color-warning)', 10),
                        borderColor: tint('var(--color-warning)', 30),
                        color: 'var(--color-ink)',
                    }}
                >
                    <FileWarning className="mt-0.5 h-4 w-4 shrink-0" style={{ color: 'var(--color-warning)' }} />
                    <span>{m['extensions.modifiedFiles.explanation']({ verb })}</span>
                </div>

                <section>
                    <h3 className="mb-1.5 text-xs font-medium uppercase tracking-wide text-[var(--color-ink-muted)]">
                        {m['extensions.modifiedFiles.changed']({ count: paths.length })}
                    </h3>
                    {/* Long paths scroll inside this box rather than widening the modal. */}
                    <ul className="max-h-56 space-y-1 overflow-auto rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-2)] p-2">
                        {paths.map(path => (
                            <li key={path} className="break-all text-xs text-[var(--color-ink-muted)]">
                                <code>{path}</code>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </Modal>
    );
}
