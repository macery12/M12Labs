import { m } from '@/i18n/messages';
import { ShieldAlert, Plus, Minus } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import type { CapabilityDiff } from '@/api/extensions';

const tint = (v: string, pct: number) => `color-mix(in srgb, ${v} ${pct}%, transparent)`;

/**
 * Consent step for privileges a release asks for.
 *
 * The panel computes this from the verified manifest, not from what the
 * repository advertises, so the list is what the package actually declares.
 * Escalations are called out separately from the rest of the diff: adding a
 * page or a setting is worth seeing, but only routes, permissions, hooks,
 * queues, secrets, commands, tables, migrations and schedules widen what the
 * extension can reach.
 */
export function CapabilityApprovalModal({
    open,
    extensionName,
    diff,
    busy,
    onClose,
    onApprove,
}: {
    open: boolean;
    extensionName: string;
    diff: CapabilityDiff | null;
    busy: boolean;
    onClose: () => void;
    onApprove: (hash: string) => void;
}) {
    if (!diff) return null;

    const informational = diff.added.filter(entry => !diff.escalations.includes(entry));

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={m['extensions.capabilities.title']({ name: extensionName })}
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
                        onClick={() => onApprove(diff.hash)}
                        disabled={busy}
                        className="inline-flex h-9 items-center rounded-lg bg-[var(--brand)] px-3 text-sm font-medium text-[var(--color-brand-ink)] transition-colors hover:bg-[var(--brand-hover)] disabled:opacity-50"
                    >
                        {m['extensions.capabilities.approve']()}
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
                    <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" style={{ color: 'var(--color-warning)' }} />
                    <span>{m['extensions.capabilities.trustNote']()}</span>
                </div>

                {diff.escalations.length > 0 && (
                    <section>
                        <h3 className="mb-1.5 text-xs font-medium uppercase tracking-wide text-[var(--color-ink-muted)]">
                            {m['extensions.capabilities.granted']()}
                        </h3>
                        <ul className="space-y-1">
                            {diff.escalations.map(entry => (
                                <li key={entry} className="flex items-start gap-2 text-[var(--color-ink)]">
                                    <Plus className="mt-0.5 h-3.5 w-3.5 shrink-0" style={{ color: 'var(--color-warning)' }} />
                                    <code className="text-xs">{entry}</code>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {informational.length > 0 && (
                    <section>
                        <h3 className="mb-1.5 text-xs font-medium uppercase tracking-wide text-[var(--color-ink-muted)]">
                            {m['extensions.capabilities.alsoAdded']()}
                        </h3>
                        <ul className="space-y-1">
                            {informational.map(entry => (
                                <li key={entry} className="flex items-start gap-2 text-[var(--color-ink-muted)]">
                                    <Plus className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                    <code className="text-xs">{entry}</code>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {diff.removed.length > 0 && (
                    <section>
                        <h3 className="mb-1.5 text-xs font-medium uppercase tracking-wide text-[var(--color-ink-muted)]">
                            {m['extensions.capabilities.removed']()}
                        </h3>
                        <ul className="space-y-1">
                            {diff.removed.map(entry => (
                                <li key={entry} className="flex items-start gap-2 text-[var(--color-ink-faint)]">
                                    <Minus className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                    <code className="text-xs line-through">{entry}</code>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </Modal>
    );
}
