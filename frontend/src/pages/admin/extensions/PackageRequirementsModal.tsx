import { useState } from 'react';
import { AlertTriangle, Check, Copy, RefreshCw } from 'lucide-react';
import { m } from '@/i18n/messages';
import { Modal } from '@/components/ui/Modal';
import type { PackageRequirementFailure } from '@/api/extensions';

const tint = (value: string, amount: number) => `color-mix(in srgb, ${value} ${amount}%, transparent)`;

export function PackageRequirementsModal({
    open,
    extensionName,
    requirements,
    operation,
    busy,
    onClose,
    onRetry,
}: {
    open: boolean;
    extensionName: string;
    requirements: PackageRequirementFailure | null;
    operation: 'install' | 'update';
    busy: boolean;
    onClose: () => void;
    onRetry: () => void;
}) {
    const [copied, setCopied] = useState<string | null>(null);

    if (!requirements) return null;

    const copy = async (manager: string, command: string) => {
        if (!navigator.clipboard) return;
        await navigator.clipboard.writeText(command);
        setCopied(manager);
        window.setTimeout(() => setCopied(current => (current === manager ? null : current)), 1800);
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={m['extensions.requirements.title']({ name: extensionName })}
            size="lg"
            footer={
                <div className="flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={busy}
                        className="inline-flex h-9 items-center rounded-lg border border-[var(--color-border-strong)] px-3 text-sm text-[var(--color-ink-muted)] transition-colors hover:bg-[var(--color-surface-2)] disabled:opacity-50"
                    >
                        {m['common.actions.close']()}
                    </button>
                    <button
                        type="button"
                        onClick={onRetry}
                        disabled={busy}
                        className="inline-flex h-9 items-center gap-2 rounded-lg bg-[var(--brand)] px-3 text-sm font-medium text-[var(--color-brand-ink)] transition-colors hover:bg-[var(--brand-hover)] disabled:opacity-50"
                    >
                        <RefreshCw className={busy ? 'h-3.5 w-3.5 animate-spin' : 'h-3.5 w-3.5'} />
                        {operation === 'install'
                            ? m['extensions.requirements.retry']()
                            : m['extensions.requirements.retryUpdate']()}
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
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" style={{ color: 'var(--color-warning)' }} />
                    <span>{m['extensions.requirements.description']()}</span>
                </div>

                <div className="overflow-x-auto rounded-lg border border-[var(--color-border)]">
                    <table className="w-full min-w-[36rem] text-left text-xs">
                        <thead className="bg-[var(--color-surface-2)] text-[var(--color-ink-muted)]">
                            <tr>
                                <th className="px-3 py-2 font-medium">{m['extensions.requirements.type']()}</th>
                                <th className="px-3 py-2 font-medium">{m['extensions.requirements.package']()}</th>
                                <th className="px-3 py-2 font-medium">{m['extensions.requirements.required']()}</th>
                                <th className="px-3 py-2 font-medium">{m['extensions.requirements.installed']()}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[var(--color-border)]">
                            {requirements.problems.map(problem => (
                                <tr key={`${problem.manager}:${problem.package}`}>
                                    <td className="px-3 py-2 text-[var(--color-ink-muted)]">
                                        {problem.type === 'frontend'
                                            ? m['extensions.requirements.frontend']()
                                            : m['extensions.requirements.backend']()}
                                    </td>
                                    <td className="px-3 py-2">
                                        <code className="text-[var(--color-ink)]">{problem.package}</code>
                                    </td>
                                    <td className="px-3 py-2">
                                        <code className="text-[var(--color-ink-muted)]">{problem.required}</code>
                                    </td>
                                    <td className="px-3 py-2">
                                        {problem.installed === null ? (
                                            <span className="text-[var(--color-danger)]">{m['extensions.requirements.notInstalled']()}</span>
                                        ) : (
                                            <code className="text-[var(--color-danger)]">{problem.installed}</code>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {Object.entries(requirements.commands).length > 0 && (
                    <section className="space-y-2">
                        <p className="text-xs leading-relaxed text-[var(--color-ink-muted)]">
                            {m['extensions.requirements.commandHint']()}
                        </p>
                        {Object.entries(requirements.commands).map(([manager, command]) => (
                            <div key={manager} className="flex items-center gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-2)] p-2">
                                <code className="min-w-0 flex-1 select-all overflow-x-auto whitespace-nowrap text-xs text-[var(--color-ink)]">
                                    {command}
                                </code>
                                <button
                                    type="button"
                                    onClick={() => void copy(manager, command)}
                                    className="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border border-[var(--color-border-strong)] px-2 text-xs text-[var(--color-ink-muted)] hover:bg-[var(--color-surface)]"
                                >
                                    {copied === manager ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
                                    {copied === manager ? m['extensions.requirements.copied']() : m['extensions.requirements.copy']()}
                                </button>
                            </div>
                        ))}
                    </section>
                )}
            </div>
        </Modal>
    );
}
