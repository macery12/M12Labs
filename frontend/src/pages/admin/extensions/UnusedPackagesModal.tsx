import { useState } from 'react';
import { Check, Copy, Info } from 'lucide-react';
import { m } from '@/i18n/messages';
import { Modal } from '@/components/ui/Modal';
import type { PossiblyUnusedPackages } from '@/api/extensions';

export function hasPossiblyUnusedPackages(packages: PossiblyUnusedPackages): boolean {
    return packages.npmPackages.length > 0 || packages.composerPackages.length > 0;
}

export function UnusedPackagesModal({
    open,
    packages,
    onClose,
}: {
    open: boolean;
    packages: PossiblyUnusedPackages | null;
    onClose: () => void;
}) {
    const [copied, setCopied] = useState<string | null>(null);

    if (!packages || !hasPossiblyUnusedPackages(packages)) return null;

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
            title={m['extensions.unusedPackages.title']()}
            footer={
                <button
                    type="button"
                    onClick={onClose}
                    className="inline-flex h-9 items-center rounded-lg bg-[var(--brand)] px-3 text-sm font-medium text-[var(--color-brand-ink)] hover:bg-[var(--brand-hover)]"
                >
                    {m['common.actions.close']()}
                </button>
            }
        >
            <div className="space-y-4 text-sm">
                <div className="flex gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-2)] px-3 py-2.5 text-xs leading-relaxed text-[var(--color-ink-muted)]">
                    <Info className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{m['extensions.unusedPackages.description']()}</span>
                </div>

                {packages.npmPackages.length > 0 && (
                    <PackageList title={m['extensions.unusedPackages.frontend']()} packages={packages.npmPackages} />
                )}
                {packages.composerPackages.length > 0 && (
                    <PackageList title={m['extensions.unusedPackages.backend']()} packages={packages.composerPackages} />
                )}

                {Object.entries(packages.commands).map(([manager, command]) => (
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
            </div>
        </Modal>
    );
}

function PackageList({ title, packages }: { title: string; packages: string[] }) {
    return (
        <section>
            <h3 className="mb-1.5 text-xs font-medium uppercase tracking-wide text-[var(--color-ink-muted)]">{title}</h3>
            <ul className="space-y-1 rounded-lg border border-[var(--color-border)] p-2">
                {packages.map(name => (
                    <li key={name} className="text-xs text-[var(--color-ink)]">
                        <code>{name}</code>
                    </li>
                ))}
            </ul>
        </section>
    );
}
