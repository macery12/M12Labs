import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Activity, AlertTriangle, CheckCircle2, Copy, Check } from 'lucide-react';
import { m } from '@/i18n/messages';
import { Spinner } from '@/components/ui/Spinner';
import { useFlashes } from '@/state/flashes';
import { getExtensionHealth, getExtensionHealthExport, type ExtensionHealth } from '@/api/extensions';

/**
 * Runtime diagnostics for an installed extension.
 *
 * The question this exists to answer is the one the drawer could not: an
 * extension can read as enabled and do nothing, because loading also requires
 * an executable lifecycle state, a capability projection that still matches its
 * manifest, and an acceptable signature. Each of those gets its own line, so
 * the answer is "this specific thing is wrong" rather than "it says enabled".
 */
/**
 * Why the panel is refusing to load an extension, in the operator's terms.
 *
 * The generic "lifecycle state is enabled" is unhelpful exactly when it matters
 * — the state is fine and something else (an untrusted signature, a stale
 * capability record) is the real cause — so the backend names the check that
 * failed and this maps it to an actionable sentence.
 */
function notLoadableLabel(health: ExtensionHealth): string {
    const state = health.state ?? '';

    switch (health.notLoadableReason) {
        case 'module_disabled':
            return m['extensions.health.reason.module_disabled']();
        case 'config_disabled':
            return m['extensions.health.reason.config_disabled']();
        case 'manifest_version':
            return m['extensions.health.reason.manifest_version']();
        case 'signature':
            return m['extensions.health.reason.signature']();
        case 'capabilities_missing':
            return m['extensions.health.reason.capabilities_missing']();
        case 'capability_hash':
            return m['extensions.health.reason.capability_hash']();
        default:
            return m['extensions.health.reason.state']({ state });
    }
}

export function ExtensionHealthPanel({ extensionId }: { extensionId: string }) {
    const { push } = useFlashes();
    const [copied, setCopied] = useState(false);

    const { data: health, isPending } = useQuery({
        queryKey: ['admin', 'extension-health', extensionId],
        queryFn: () => getExtensionHealth(extensionId),
        // The backend caches for 30s; matching it here avoids a refetch storm
        // when the drawer is opened and closed repeatedly.
        staleTime: 30_000,
    });

    if (isPending) {
        return (
            <p className="flex items-center gap-2 text-xs text-[var(--color-ink-muted)]">
                <Spinner className="h-3.5 w-3.5" />
                {m['extensions.health.loading']()}
            </p>
        );
    }

    if (!health?.installed) {
        return <p className="text-xs text-[var(--color-ink-faint)]">{m['extensions.health.notInstalled']()}</p>;
    }

    const copyExport = async () => {
        try {
            const report = await getExtensionHealthExport(extensionId);
            await navigator.clipboard.writeText(JSON.stringify(report, null, 2));
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            push({ type: 'error', message: m['extensions.health.copyFailed']() });
        }
    };

    return (
        <div className="space-y-2.5">
            <StatusRow
                ok={health.loadable === true}
                okLabel={m['extensions.health.loadable']()}
                failLabel={health.stateReason || notLoadableLabel(health)}
            />

            <StatusRow
                ok={health.integrity?.capabilityProjectionMatches !== false}
                okLabel={m['extensions.health.projectionOk']()}
                failLabel={m['extensions.health.projectionMismatch']()}
            />

            <StatusRow
                ok={(health.integrity?.missingFiles.length ?? 0) === 0 && (health.integrity?.modifiedFiles.length ?? 0) === 0}
                okLabel={m['extensions.health.filesOk']({ count: health.integrity?.trackedFiles ?? 0 })}
                failLabel={m['extensions.health.filesChanged']({
                    missing: health.integrity?.missingFiles.length ?? 0,
                    modified: health.integrity?.modifiedFiles.length ?? 0,
                })}
            />

            {health.signature && (
                <StatusRow
                    ok={!health.signature.enforced || health.signature.state === 'verified'}
                    okLabel={m['extensions.health.signature']({ state: health.signature.state })}
                    failLabel={m['extensions.health.signature']({ state: health.signature.state })}
                />
            )}

            {(health.permissions?.pendingApproval ?? 0) > 0 && (
                <StatusRow
                    ok={false}
                    okLabel=""
                    failLabel={m['extensions.health.pendingPermissions']({ count: health.permissions!.pendingApproval })}
                />
            )}

            {health.queues?.lastFailure && (
                <StatusRow
                    ok={false}
                    okLabel=""
                    failLabel={m['extensions.health.lastJobFailure']({
                        error: health.queues.lastFailure.error ?? '',
                    })}
                />
            )}

            {(health.hooks ?? []).filter(h => h.breakerOpen || h.quarantined).map(hook => (
                <StatusRow
                    key={`${hook.event}:${hook.handler}`}
                    ok={false}
                    okLabel=""
                    failLabel={m['extensions.health.hookSuspended']({
                        handler: hook.handler,
                        event: hook.event,
                    })}
                />
            ))}

            <button
                type="button"
                onClick={copyExport}
                className="inline-flex items-center gap-1.5 rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-2.5 py-1.5 text-xs font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-2)]"
            >
                {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
                {copied ? m['extensions.health.copied']() : m['extensions.health.copyExport']()}
            </button>
            <p className="text-[11px] text-[var(--color-ink-faint)]">{m['extensions.health.exportHint']()}</p>
        </div>
    );
}

function StatusRow({ ok, okLabel, failLabel }: { ok: boolean; okLabel: string; failLabel: string }) {
    return (
        <div className="flex items-start gap-2 text-xs">
            {ok ? (
                <CheckCircle2 className="mt-px h-3.5 w-3.5 shrink-0 text-[var(--color-success)]" />
            ) : (
                <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0 text-[var(--color-warning)]" />
            )}
            <span className={ok ? 'text-[var(--color-ink-muted)]' : 'text-[var(--color-ink)]'}>
                {ok ? okLabel : failLabel}
            </span>
        </div>
    );
}

export const ExtensionHealthIcon = Activity;
