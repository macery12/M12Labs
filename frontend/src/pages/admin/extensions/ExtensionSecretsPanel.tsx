import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { KeyRound, Check, Trash2 } from 'lucide-react';
import { m, td } from '@/i18n/messages';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { useFlashes } from '@/state/flashes';
import { refreshExtensionFlags } from '@/extensions-sdk/flags';
import {
    getExtensionSecrets,
    putExtensionSecret,
    deleteExtensionSecret,
    type ExtensionSecret,
} from '@/api/extensions';

/**
 * Credential fields for an installed extension.
 *
 * Deliberately write-only. The API never returns a stored value, so there is
 * nothing to prefill and nothing a screenshot or a copied response can leak;
 * the field shows whether something is configured and when it last changed.
 * Submitting an empty field is a no-op rather than a clear, so saving the
 * drawer for an unrelated reason cannot destroy a working credential — clearing
 * is its own explicit button.
 */
export function ExtensionSecretsPanel({ extensionId, disabled }: { extensionId: string; disabled: boolean }) {
    const qc = useQueryClient();
    const { push } = useFlashes();
    const [drafts, setDrafts] = useState<Record<string, string>>({});

    const { data: secrets, isPending } = useQuery({
        queryKey: ['admin', 'extension-secrets', extensionId],
        queryFn: () => getExtensionSecrets(extensionId),
    });

    const write = useMutation({
        mutationFn: ({ key, value }: { key: string; value: string }) =>
            putExtensionSecret(extensionId, key, value),
        onSuccess: (data, vars) => {
            qc.setQueryData(['admin', 'extension-secrets', extensionId], data);
            setDrafts(d => ({ ...d, [vars.key]: '' }));
            void refreshExtensionFlags().catch(() => undefined);
            push({ type: 'success', message: m['extensions.secrets.saved']() });
        },
        onError: () => push({ type: 'error', message: m['extensions.secrets.saveFailed']() }),
    });

    const clear = useMutation({
        mutationFn: (key: string) => deleteExtensionSecret(extensionId, key),
        onSuccess: data => {
            qc.setQueryData(['admin', 'extension-secrets', extensionId], data);
            void refreshExtensionFlags().catch(() => undefined);
            push({ type: 'success', message: m['extensions.secrets.cleared']() });
        },
        onError: () => push({ type: 'error', message: m['extensions.secrets.saveFailed']() }),
    });

    if (isPending) {
        return (
            <p className="flex items-center gap-2 text-xs text-[var(--color-ink-muted)]">
                <Spinner className="h-3.5 w-3.5" />
                {m['extensions.secrets.loading']()}
            </p>
        );
    }

    if (!secrets || secrets.length === 0) {
        return <p className="text-xs text-[var(--color-ink-faint)]">{m['extensions.secrets.none']()}</p>;
    }

    const busy = write.isPending || clear.isPending || disabled;

    return (
        <div className="space-y-3">
            {secrets.map((secret: ExtensionSecret) => {
                const draft = drafts[secret.key] ?? '';

                return (
                    <div
                        key={secret.key}
                        className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-2)]/40 px-3 py-2.5"
                    >
                        <div className="mb-1.5 flex items-center justify-between gap-2">
                            <span className="text-xs font-medium text-[var(--color-ink)]">
                                {td(secret.labelKey, secret.key)}
                            </span>
                            {secret.configured ? (
                                <span className="inline-flex items-center gap-1 text-[11px] text-[var(--color-success)]">
                                    <Check className="h-3 w-3" />
                                    {m['extensions.secrets.configured']()}
                                </span>
                            ) : (
                                <span className="text-[11px] text-[var(--color-ink-faint)]">
                                    {m['extensions.secrets.notConfigured']()}
                                </span>
                            )}
                        </div>

                        {secret.helpKey && (
                            <p className="mb-1.5 text-[11px] text-[var(--color-ink-faint)]">{td(secret.helpKey, '')}</p>
                        )}

                        <div className="flex items-center gap-2">
                            <Input
                                type="password"
                                autoComplete="off"
                                value={draft}
                                disabled={busy}
                                placeholder={
                                    secret.configured
                                        ? m['extensions.secrets.replacePlaceholder']()
                                        : m['extensions.secrets.enterPlaceholder']()
                                }
                                onChange={e => setDrafts(d => ({ ...d, [secret.key]: e.target.value }))}
                                className="flex-1"
                            />
                            <button
                                type="button"
                                disabled={busy || draft.trim() === ''}
                                onClick={() => write.mutate({ key: secret.key, value: draft })}
                                className="inline-flex items-center gap-1.5 rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-2.5 py-1.5 text-xs font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-2)] disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <KeyRound className="h-3.5 w-3.5" />
                                {m['extensions.secrets.save']()}
                            </button>
                            {secret.configured && (
                                <button
                                    type="button"
                                    disabled={busy}
                                    title={m['extensions.secrets.clear']()}
                                    onClick={() => clear.mutate(secret.key)}
                                    className="inline-flex items-center rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-2 py-1.5 text-[var(--color-danger)] transition-colors hover:bg-[var(--color-surface-2)] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </button>
                            )}
                        </div>

                        {secret.updatedAt && (
                            <p className="mt-1.5 text-[11px] text-[var(--color-ink-faint)]">
                                {m['extensions.secrets.lastChanged']({
                                    when: new Date(secret.updatedAt).toLocaleString(),
                                })}
                            </p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
