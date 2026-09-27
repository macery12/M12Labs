import { m } from '@/i18n/messages';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { ChevronLeft, Copy, Check, ExternalLink, MoreHorizontal, Power, PowerOff, RefreshCw, Trash2 } from 'lucide-react';
import { useServerView } from './ServerContext';
import { ServerStatusBadges, usePowerStates } from '@/pages/admin/servers/ServerStatus';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { can } from '@/lib/can';
import { useAdminHeld } from '@/layouts/heldPermissions';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import { suspendServer, unsuspendServer, reinstallServer, deleteServer } from '@/api/adminServers';

const MENU_ITEM =
    'flex cursor-pointer items-center gap-2.5 rounded-md px-3 py-2 text-sm text-[var(--color-ink-muted)] outline-none hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)] focus:bg-[var(--color-surface-2)] focus:text-[var(--color-ink)]';

export function ServerHeader() {
    const server = useServerView();
    const navigate = useNavigate();
    const qc = useQueryClient();
    const push = useFlashes(s => s.push);
    const held = useAdminHeld();
    const [copied, setCopied] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [reinstalling, setReinstalling] = useState(false);

    const canUpdate = can(held, 'servers.update');
    const canDelete = can(held, 'servers.delete');
    const power = usePowerStates([server.nodeId])(server);
    const suspended = server.state === 'suspended';

    const copy = () => {
        navigator.clipboard?.writeText(server.uuid).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    };

    const [busy, setBusy] = useState(false);

    const run = async (fn: () => Promise<void>, msg: string) => {
        setBusy(true);
        try {
            await fn();
            push({ type: 'success', message: msg });
            await qc.invalidateQueries({ queryKey: ['admin', 'server-view', String(server.id)] });
            await qc.invalidateQueries({ queryKey: ['admin', 'servers'] });
        } catch (err) {
            push({ type: 'error', message: firstError(err) ?? m['common.states.genericError']() });
        } finally {
            setBusy(false);
        }
    };

    const del = useMutation({
        mutationFn: (force: boolean) => deleteServer(server.id, force),
        onSuccess: async () => {
            push({ type: 'success', message: m['admin.infrastructure.server.deleted']() });
            await qc.invalidateQueries({ queryKey: ['admin', 'servers'] });
            navigate('/admin/infrastructure');
        },
        onError: () => push({ type: 'error', message: m['common.states.genericError']() }),
    });

    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex min-w-0 items-center gap-3">
                <Link
                    to="/admin/infrastructure"
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-[var(--color-border-strong)] text-[var(--color-ink-muted)] transition-colors hover:bg-[var(--color-surface-2)]"
                    title={m['admin.infrastructure.title']()}
                >
                    <ChevronLeft className="h-4 w-4" />
                </Link>
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="truncate text-xl font-semibold tracking-tight text-[var(--color-ink)]">{server.name}</h1>
                        <ServerStatusBadges power={power} lifecycle={server.state} />
                    </div>
                    <button
                        onClick={copy}
                        className="group mt-0.5 flex items-center gap-1.5 font-mono text-xs text-[var(--color-ink-muted)] transition-colors hover:text-[var(--color-ink)]"
                    >
                        {server.identifier} · {server.uuid}
                        {copied ? <Check className="h-3 w-3 text-[var(--color-accent)]" /> : <Copy className="h-3 w-3 opacity-0 transition-opacity group-hover:opacity-100" />}
                    </button>
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-2">
                {/* Client-side view. Keyed on the 8-char identifier, which is what the
                    client API binds {server} against — not the numeric admin id. */}
                <Link
                    to={`/server/${server.identifier}`}
                    className="inline-flex h-9 items-center gap-2 rounded-lg border border-[var(--color-border-strong)] px-3 text-sm font-medium text-[var(--color-ink)] transition-colors hover:bg-[var(--color-surface-2)]"
                >
                    <ExternalLink className="h-4 w-4" /> {m['admin.infrastructure.server.viewAsUser']()}
                </Link>
                {(canUpdate || canDelete) && (
                    <>
                        {canUpdate && (
                            <>
                                {suspended ? (
                                    <Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => unsuspendServer(server.id), m['admin.infrastructure.server.unsuspended']())}>
                                        <Power className="h-4 w-4" /> {m['admin.infrastructure.server.unsuspend']()}
                                    </Button>
                                ) : (
                                    <Button variant="outline" size="sm" disabled={busy} onClick={() => run(() => suspendServer(server.id), m['admin.infrastructure.server.suspended']())}>
                                        <PowerOff className="h-4 w-4" /> {m['admin.infrastructure.server.suspend']()}
                                    </Button>
                                )}
                            </>
                        )}
                        {/* Reinstall and Delete used to sit beside Suspend as one-click
                            buttons (Reinstall had no confirmation at all). They live
                            behind a menu now, away from the everyday actions. */}
                        <DropdownMenu.Root>
                            <DropdownMenu.Trigger asChild>
                                <Button variant="outline" size="icon" className="h-9 w-9" aria-label={m['admin.infrastructure.server.moreActions']()} disabled={busy}>
                                    <MoreHorizontal className="h-4 w-4" />
                                </Button>
                            </DropdownMenu.Trigger>
                            <DropdownMenu.Portal>
                                <DropdownMenu.Content
                                    align="end"
                                    sideOffset={6}
                                    className="z-50 min-w-44 rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface)] p-1.5 shadow-xl"
                                >
                                    {canUpdate && (
                                        <DropdownMenu.Item
                                            onSelect={() => setReinstalling(true)}
                                            className={MENU_ITEM}
                                        >
                                            <RefreshCw className="h-4 w-4" /> {m['admin.infrastructure.server.reinstall']()}
                                        </DropdownMenu.Item>
                                    )}
                                    {canUpdate && canDelete && (
                                        <DropdownMenu.Separator className="my-1 h-px bg-[var(--color-border)]" />
                                    )}
                                    {canDelete && (
                                        <DropdownMenu.Item
                                            onSelect={() => setDeleting(true)}
                                            className={`${MENU_ITEM} text-[var(--color-danger)] focus:text-[var(--color-danger)]`}
                                        >
                                            <Trash2 className="h-4 w-4" /> {m['common.actions.delete']()}
                                        </DropdownMenu.Item>
                                    )}
                                </DropdownMenu.Content>
                            </DropdownMenu.Portal>
                        </DropdownMenu.Root>
                        {busy && <Spinner className="h-4 w-4" />}
                    </>
                )}
            </div>

            <ConfirmDialog
                open={reinstalling}
                onClose={() => setReinstalling(false)}
                title={m['admin.infrastructure.server.reinstallTitle']()}
                body={m['admin.infrastructure.server.reinstallBody']({ name: server.name })}
                confirmLabel={m['admin.infrastructure.server.reinstall']()}
                cancelLabel={m['common.actions.cancel']()}
                busy={busy}
                onConfirm={async () => {
                    await run(() => reinstallServer(server.id), m['admin.infrastructure.server.reinstalled']());
                    setReinstalling(false);
                }}
            />

            <ConfirmDialog
                open={deleting}
                onClose={() => setDeleting(false)}
                title={m['admin.infrastructure.server.deleteTitle']()}
                body={m['admin.infrastructure.server.deleteBody']({ name: server.name })}
                confirmLabel={m['common.actions.delete']()}
                cancelLabel={m['common.actions.cancel']()}
                busy={del.isPending}
                force={{ label: m['admin.infrastructure.server.forceDelete']() }}
                onConfirm={force => del.mutate(force)}
            />
        </div>
    );
}
