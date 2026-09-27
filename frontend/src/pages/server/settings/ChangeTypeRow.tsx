import { useState } from 'react';
import { useQuery, useMutation } from '@tanstack/react-query';
import { AlertTriangle, Puzzle } from 'lucide-react';
import { m } from '@/i18n/messages';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Switch } from '@/components/ui/Switch';
import { useServer } from '@/components/server/ServerContext';
import { useServerSocket } from '@/state/serverSocket';
import { useFlashes } from '@/state/flashes';
import { getEggInfo, getStoreProduct, getStoreCategories, type EggInfo } from '@/api/accountBilling';
import { changeEgg } from '@/api/serverBilling';
import { Notice } from '../billing/parts';

const DELETE_PHRASE = 'DELETE';

/**
 * Which eggs this server may switch to. The product's allowed list wins; if the
 * product is gone or lists only the current egg, fall back to the category that
 * contains it. Either source can also forbid changes outright. Ported from V1's
 * ChangeEggContainer, which lived under Settings.
 */
async function resolveEggOptions(
    currentEggId: number,
    billingProductId: number | null,
): Promise<{ eggs: EggInfo[]; allowed: boolean }> {
    const currentEgg = await getEggInfo(currentEggId);

    let allowedEggIds: number[] = [currentEggId];
    let canChange = true;

    if (billingProductId) {
        try {
            const product = await getStoreProduct(billingProductId);
            allowedEggIds = product.allowedEggs.length > 0 ? product.allowedEggs : [product.eggId];
            canChange = product.allowEggChanges;
        } catch {
            // Deleted product — the category lookup below still gives us a list.
        }
    }

    if (allowedEggIds.length === 1 || !billingProductId) {
        try {
            const categories = await getStoreCategories();
            const category = categories.find(c => c.allowedEggs?.includes(currentEggId));
            if (category) {
                allowedEggIds = category.allowedEggs.length > 0 ? category.allowedEggs : allowedEggIds;
                canChange = category.allowEggChanges ?? canChange;
            }
        } catch {
            // Leave the single-egg fallback in place; the panel then hides itself.
        }
    }

    const unique = Array.from(new Set(allowedEggIds));
    const eggs = await Promise.all(unique.map(id => (id === currentEggId ? currentEgg : getEggInfo(id))));

    return { eggs, allowed: canChange && eggs.length > 1 };
}

// Switch the server's software (egg). Reinstalls the server, so it's gated on
// the server being stopped and behind an explicit confirmation.
//
// A row in Settings → Actions, beside Reinstall. It sat under Billing, where
// "change what game this is" read as a payment setting (#25); it is the same
// kind of rebuild as Reinstall and takes the same permission server-side.
export function ChangeTypeRow() {
    const server = useServer();
    const push = useFlashes(s => s.push);
    const powerState = useServerSocket(s => s.status);

    const [selectedEggId, setSelectedEggId] = useState<number | null>(null);
    const [confirming, setConfirming] = useState(false);
    const [deleteFiles, setDeleteFiles] = useState(false);
    const [confirmText, setConfirmText] = useState('');

    const eggsQ = useQuery({
        queryKey: ['server', server.id, 'egg-options', server.eggId, server.billingProductId],
        queryFn: () => resolveEggOptions(server.eggId!, server.billingProductId),
        enabled: !!server.eggId,
        staleTime: 5 * 60_000,
    });

    const change = useMutation({
        mutationFn: () => changeEgg(server.uuid, selectedEggId!, deleteFiles),
        // The reinstall rewrites startup, image, and variables — reload rather
        // than try to patch the cache back into agreement.
        onSuccess: () => window.location.reload(),
        onError: () => {
            push({ type: 'error', message: m['server.settings.type.eggChangeError']() });
            setConfirming(false);
        },
    });

    if (!server.eggId || eggsQ.isLoading) return null;
    if (!eggsQ.data?.allowed) return null;

    const eggs = eggsQ.data.eggs;
    const currentEgg = eggs.find(e => e.id === server.eggId);
    const targetId = selectedEggId ?? server.eggId;
    const targetEgg = eggs.find(e => e.id === targetId);

    // `null` is the pre-connection state; treat only a confirmed offline server
    // as safe to reinstall.
    const stopped = powerState === null || powerState === 'offline';
    const changed = targetId !== server.eggId;
    const canSubmit = changed && stopped;
    const deleteConfirmed = !deleteFiles || confirmText === DELETE_PHRASE;

    const closeDialog = () => {
        setConfirming(false);
        setDeleteFiles(false);
        setConfirmText('');
    };

    return (
        <>
            <div className="flex flex-col gap-3 border-t border-[var(--color-border)] pt-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <p className="flex items-center gap-1.5 text-sm font-medium text-[var(--color-ink)]">
                            <Puzzle className="h-4 w-4 text-[var(--color-ink-faint)]" />
                            {m['server.settings.type.changeType']()}
                        </p>
                        <p className="text-xs text-[var(--color-ink-faint)]">
                            {m['server.settings.type.desc']({ type: currentEgg?.name ?? '—' })}
                        </p>
                        {!stopped && (
                            <p className="mt-1 inline-flex items-center gap-1 text-xs text-[var(--color-warning)]">
                                <AlertTriangle className="h-3.5 w-3.5" /> {m['server.settings.type.mustBeStopped']()}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <label htmlFor="egg-select" className="sr-only">
                            {m['server.settings.type.newType']()}
                        </label>
                        <Select
                            id="egg-select"
                            value={String(targetId)}
                            onChange={value => setSelectedEggId(Number(value))}
                            options={eggs.map(egg => ({ value: String(egg.id), label: egg.name }))}
                            className="h-10 w-52"
                        />
                        {/* Red only once there's a change to make; a loud red button
                            beside the current type read as "something's wrong". */}
                        <Button
                            variant={changed ? 'danger' : 'outline'}
                            disabled={!canSubmit || change.isPending}
                            onClick={() => setConfirming(true)}
                        >
                            {m['server.settings.type.changeTypeAction']()}
                        </Button>
                    </div>
                </div>
                {canSubmit && (
                    <Notice tone="warning" icon={AlertTriangle}>
                        {m['server.settings.type.backupWarning']()}
                    </Notice>
                )}
            </div>

            <Modal
                open={confirming}
                onClose={closeDialog}
                title={m['server.settings.type.confirmEggTitle']()}
                size="sm"
                footer={
                    <>
                        <Button variant="ghost" size="sm" onClick={closeDialog} disabled={change.isPending}>
                            {m['common.actions.cancel']()}
                        </Button>
                        <Button
                            variant="danger"
                            size="sm"
                            disabled={change.isPending || !deleteConfirmed}
                            onClick={() => change.mutate()}
                        >
                            {change.isPending && <Spinner className="h-4 w-4" />}
                            {m['server.settings.type.changeTypeAction']()}
                        </Button>
                    </>
                }
            >
                <div className="space-y-3">
                    <p className="text-sm text-[var(--color-ink-muted)]">
                        {m['server.settings.type.confirmEggBody']({
                            from: currentEgg?.name ?? '—',
                            to: targetEgg?.name ?? '—',
                        })}
                    </p>

                    <Notice tone="warning" icon={AlertTriangle}>
                        <p className="font-medium">{m['server.settings.type.backupHeading']()}</p>
                        <p className="mt-1">{m['server.settings.type.backupDetail']()}</p>
                    </Notice>

                    <div className="rounded-lg border border-[var(--color-danger)]/40 bg-[var(--color-danger)]/10 p-3">
                        <div className="flex items-start gap-3">
                            <Switch checked={deleteFiles} onChange={setDeleteFiles} disabled={change.isPending} />
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-[var(--color-ink)]">
                                    {m['server.settings.type.deleteFiles']()}
                                </p>
                                <p className="mt-0.5 text-xs text-[var(--color-ink-muted)]">
                                    {m['server.settings.type.deleteFilesDetail']()}
                                </p>
                            </div>
                        </div>

                        {deleteFiles && (
                            <div className="mt-3 border-t border-[var(--color-danger)]/30 pt-3">
                                <label
                                    htmlFor="egg-delete-confirm"
                                    className="text-xs font-medium text-[var(--color-danger)]"
                                >
                                    {m['server.settings.type.typeToConfirm']({ phrase: DELETE_PHRASE })}
                                </label>
                                <Input
                                    id="egg-delete-confirm"
                                    autoFocus
                                    className="mt-1.5 h-9"
                                    value={confirmText}
                                    placeholder={DELETE_PHRASE}
                                    onChange={e => setConfirmText(e.target.value.toUpperCase())}
                                />
                            </div>
                        )}
                    </div>

                    <p className="text-xs text-[var(--color-ink-muted)]">
                        {deleteFiles
                            ? m['server.settings.type.eggOutcomeDelete']()
                            : m['server.settings.type.eggOutcomeKeep']()}
                    </p>
                </div>
            </Modal>
        </>
    );
}
