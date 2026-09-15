import { useEffect, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import { m } from '@/i18n/messages';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import { formatBytes } from '@/lib/format';
import { cancelPull, listPulls } from '@/api/files';

// How often to ask the panel for pull progress, and how many consecutive empty
// answers to accept before giving up on a pull we never saw listed (it finished
// between the POST and the first poll, or the daemon never reported it).
const POLL_MS = 1500;
const EMPTY_POLLS_BEFORE_STOP = 4;

/**
 * Follows in-flight remote pulls to completion.
 *
 * A background pull is only acknowledged, so without this the file simply never
 * appears and nothing says it arrived. Polling stays inside the authenticated
 * client API — the browser never addresses the daemon, and the endpoint is bound
 * to this server and gated on the same permission as starting a pull, so
 * watching progress grants no authority that starting the pull did not.
 */
export function PullProgressTray({
    uuid,
    directory,
    active,
    onIdle,
}: {
    uuid: string;
    directory: string;
    active: boolean;
    onIdle: () => void;
}) {
    const qc = useQueryClient();
    const push = useFlashes(s => s.push);
    const sawAny = useRef(false);
    const emptyPolls = useRef(0);

    const { data: pulls } = useQuery({
        queryKey: ['server-pulls', uuid],
        queryFn: () => listPulls(uuid),
        enabled: active,
        refetchInterval: active ? POLL_MS : false,
        // Progress is inherently stale the moment it arrives; never serve a cached
        // snapshot as if it were current.
        staleTime: 0,
        gcTime: 0,
    });

    // Reset the run-length counters whenever a new run of polling begins.
    useEffect(() => {
        if (!active) {
            sawAny.current = false;
            emptyPolls.current = 0;
        }
    }, [active]);

    useEffect(() => {
        if (!active || pulls === undefined) return;

        if (pulls.length > 0) {
            sawAny.current = true;
            emptyPolls.current = 0;
            return;
        }

        emptyPolls.current += 1;

        // An empty list after we had seen a pull means it is done. An empty list
        // we never saw fill means it completed too fast to observe — either way,
        // refresh the listing so the file shows up.
        if (sawAny.current || emptyPolls.current >= EMPTY_POLLS_BEFORE_STOP) {
            if (sawAny.current) push({ type: 'success', message: m['server.files.pull.finished']() });
            void qc.invalidateQueries({ queryKey: ['server-files', uuid, directory] });
            onIdle();
        }
    }, [pulls, active, uuid, directory, qc, push, onIdle]);

    const abort = useMutation({
        mutationFn: (identifier: string) => cancelPull(uuid, identifier),
        onSuccess: async () => {
            push({ type: 'info', message: m['server.files.pull.cancelled']() });
            await qc.invalidateQueries({ queryKey: ['server-pulls', uuid] });
        },
        onError: (e: unknown) => push({ type: 'error', message: firstError(e) ?? m['common.states.genericError']() }),
    });

    if (!active || !pulls || pulls.length === 0) return null;

    return (
        <div className="fixed bottom-6 left-6 z-[65] w-80 max-w-[calc(100vw-3rem)] overflow-hidden rounded-[var(--radius-card)] border border-[var(--color-border-strong)] bg-[var(--color-surface)] shadow-2xl shadow-black/40">
            <div className="border-b border-[var(--color-border)] bg-[var(--color-surface-2)] px-4 py-2.5">
                <span className="text-sm font-semibold text-[var(--color-ink)]">
                    {m['server.files.pull.progressTitle']()}
                </span>
            </div>
            <div className="max-h-64 space-y-2.5 overflow-y-auto p-4">
                {pulls.map(pull => {
                    // The daemon reports total 0 when the source sends no
                    // content-length, so there is genuinely no percentage to show.
                    const known = pull.total > 0;
                    const pct = known ? Math.min(100, Math.round((pull.progress / pull.total) * 100)) : 0;
                    const name = pull.destination.split('/').filter(Boolean).pop() ?? pull.destination;

                    return (
                        <div key={pull.identifier}>
                            <div className="flex items-center justify-between gap-2 text-xs">
                                <span className="min-w-0 flex-1 truncate text-[var(--color-ink-muted)]" title={pull.destination}>
                                    {name}
                                </span>
                                <span className="shrink-0 tabular-nums text-[var(--color-ink-faint)]">
                                    {known
                                        ? `${formatBytes(pull.progress)} / ${formatBytes(pull.total)}`
                                        : pull.progress > 0
                                          ? formatBytes(pull.progress)
                                          : m['server.files.pull.waiting']()}
                                </span>
                                <button
                                    onClick={() => abort.mutate(pull.identifier)}
                                    disabled={abort.isPending}
                                    className="shrink-0 text-[var(--color-ink-faint)] transition-colors hover:text-[var(--color-danger)]"
                                    aria-label={m['common.actions.cancel']()}
                                >
                                    <X className="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-[var(--color-surface-2)]">
                                <div
                                    className={`h-full rounded-full bg-[var(--brand)] ${
                                        known ? 'transition-[width] duration-200' : 'animate-pulse'
                                    }`}
                                    style={{ width: known ? `${pct}%` : '100%' }}
                                />
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
