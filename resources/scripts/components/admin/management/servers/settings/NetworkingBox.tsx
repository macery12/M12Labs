import { faNetworkWired, faPlus, faTrash, faStar } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useEffect, useState } from 'react';
import tw from 'twin.macro';

import getAllocations, { Allocation } from '@/api/routes/admin/nodes/getAllocations';
import { useServerFromRoute } from '@/api/routes/admin/server';
import updateServer from '@/api/routes/admin/servers/updateServer';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import { useStoreActions } from 'easy-peasy';
import { Dialog } from '@/elements/dialog';
import Label from '@/elements/Label';
import Spinner from '@/elements/Spinner';

export default () => {
    const { data: server, mutate } = useServerFromRoute();
    const { clearFlashes, clearAndAddHttpError } = useStoreActions(actions => actions.flashes);
    const [availableAllocations, setAvailableAllocations] = useState<Allocation[]>([]);
    const [selectedAvailableId, setSelectedAvailableId] = useState<number | null>(null);
    const [selectedCurrentId, setSelectedCurrentId] = useState<number | null>(null);
    const [loading, setLoading] = useState(false);
    const [loadingAvailable, setLoadingAvailable] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);

    // Load available allocations when modal opens
    useEffect(() => {
        if (!server || !modalOpen) return;

        setLoadingAvailable(true);
        getAllocations(server.nodeId, { server_id: '0' })
            .then(allocs => setAvailableAllocations(allocs))
            .catch(error => console.error('Failed to load allocations:', error))
            .finally(() => setLoadingAvailable(false));
    }, [server, modalOpen]);

    if (!server) return null;

    const currentAllocations = server.relationships.allocations || [];
    const allocationLimit = server.featureLimits?.allocations || 0;
    const canAddMore = allocationLimit === 0 || currentAllocations.length < allocationLimit;

    const handleAddAllocation = async () => {
        if (!selectedAvailableId) return;

        setLoading(true);
        clearFlashes('server:networking');

        try {
            await updateServer(server.id, {
                externalId: server.externalId || '',
                name: server.name,
                ownerId: server.ownerId,
                limits: {
                    memory: server.limits.memory,
                    swap: server.limits.swap,
                    disk: server.limits.disk,
                    io: server.limits.io,
                    cpu: server.limits.cpu,
                    threads: server.limits.threads || '',
                    oomKiller: server.limits.oomKiller,
                },
                featureLimits: {
                    allocations: server.featureLimits.allocations,
                    backups: server.featureLimits.backups,
                    databases: server.featureLimits.databases,
                    subusers: server.featureLimits.subusers,
                },
                allocationId: server.allocationId,
                addAllocations: [selectedAvailableId],
                removeAllocations: [],
            });

            await mutate();
            setSelectedAvailableId(null);
        } catch (error) {
            console.error('Failed to add allocation:', error);
            clearAndAddHttpError({ key: 'server:networking', error });
        } finally {
            setLoading(false);
        }
    };

    const handleRemoveAllocation = async () => {
        if (!selectedCurrentId) return;

        // Can't remove the primary allocation without setting a new one
        if (selectedCurrentId === server.allocationId && currentAllocations.length <= 1) {
            clearAndAddHttpError({
                key: 'server:networking',
                error: { message: 'Cannot remove the only allocation. Add another allocation first.' },
            });
            return;
        }

        setLoading(true);
        clearFlashes('server:networking');

        try {
            // If removing primary, set a new primary first
            let newPrimaryId = server.allocationId;
            if (selectedCurrentId === server.allocationId) {
                const remaining = currentAllocations.find(a => a.id !== selectedCurrentId);
                if (remaining) {
                    newPrimaryId = remaining.id;
                }
            }

            await updateServer(server.id, {
                externalId: server.externalId || '',
                name: server.name,
                ownerId: server.ownerId,
                limits: {
                    memory: server.limits.memory,
                    swap: server.limits.swap,
                    disk: server.limits.disk,
                    io: server.limits.io,
                    cpu: server.limits.cpu,
                    threads: server.limits.threads || '',
                    oomKiller: server.limits.oomKiller,
                },
                featureLimits: {
                    allocations: server.featureLimits.allocations,
                    backups: server.featureLimits.backups,
                    databases: server.featureLimits.databases,
                    subusers: server.featureLimits.subusers,
                },
                allocationId: newPrimaryId,
                addAllocations: [],
                removeAllocations: [selectedCurrentId],
            });

            await mutate();
            setSelectedCurrentId(null);
        } catch (error) {
            console.error('Failed to remove allocation:', error);
            clearAndAddHttpError({ key: 'server:networking', error });
        } finally {
            setLoading(false);
        }
    };

    const handleSetPrimary = async () => {
        if (!selectedCurrentId || selectedCurrentId === server.allocationId) return;

        setLoading(true);
        clearFlashes('server:networking');

        try {
            await updateServer(server.id, {
                externalId: server.externalId || '',
                name: server.name,
                ownerId: server.ownerId,
                limits: {
                    memory: server.limits.memory,
                    swap: server.limits.swap,
                    disk: server.limits.disk,
                    io: server.limits.io,
                    cpu: server.limits.cpu,
                    threads: server.limits.threads || '',
                    oomKiller: server.limits.oomKiller,
                },
                featureLimits: {
                    allocations: server.featureLimits.allocations,
                    backups: server.featureLimits.backups,
                    databases: server.featureLimits.databases,
                    subusers: server.featureLimits.subusers,
                },
                allocationId: selectedCurrentId,
                addAllocations: [],
                removeAllocations: [],
            });

            await mutate();
        } catch (error) {
            console.error('Failed to set primary allocation:', error);
            clearAndAddHttpError({ key: 'server:networking', error });
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <AdminBox icon={faNetworkWired} title={'Networking'}>
                <div css={tw`space-y-4`}>
                    {/* Current allocations summary */}
                    <div>
                        <Label>Current Allocations</Label>
                        <div css={tw`mt-2 space-y-2`}>
                            {currentAllocations.length === 0 ? (
                                <p css={tw`text-sm text-gray-400`}>No allocations assigned.</p>
                            ) : (
                                currentAllocations.map(allocation => (
                                    <div key={allocation.id} css={tw`flex items-center justify-between text-sm`}>
                                        <span css={tw`font-mono`}>{allocation.getDisplayText()}</span>
                                        {allocation.id === server.allocationId && (
                                            <span
                                                css={tw`text-xs bg-blue-500 px-2 py-0.5 rounded flex items-center gap-1`}
                                            >
                                                <FontAwesomeIcon icon={faStar} css={tw`text-xs`} />
                                                Primary
                                            </span>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                        {allocationLimit > 0 && (
                            <p css={tw`text-xs text-gray-400 mt-2`}>
                                {currentAllocations.length} / {allocationLimit} allocations used
                            </p>
                        )}
                    </div>

                    {/* Button to open modal */}
                    <Button type="button" onClick={() => setModalOpen(true)}>
                        <FontAwesomeIcon icon={faNetworkWired} css={tw`mr-2`} />
                        Manage Allocations
                    </Button>
                </div>
            </AdminBox>

            {/* Allocation Management Modal */}
            <Dialog
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                title="Manage Server Allocations"
                description="Add, remove, or set primary allocations for this server"
            >
                <Dialog.Icon position="top" type={loading ? 'loading' : 'info'} />
                <div css={tw`mt-4`}>
                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-6`}>
                        {/* Current Allocations */}
                        <div>
                            <div css={tw`flex items-center justify-between mb-2`}>
                                <Label>Current Allocations</Label>
                                <div css={tw`flex gap-2`}>
                                    <Button
                                        type="button"
                                        onClick={handleSetPrimary}
                                        disabled={
                                            !selectedCurrentId || selectedCurrentId === server.allocationId || loading
                                        }
                                        css={tw`text-xs px-2 py-1`}
                                    >
                                        <FontAwesomeIcon icon={faStar} css={tw`mr-1`} />
                                        Set Primary
                                    </Button>
                                    <Button
                                        type="button"
                                        onClick={handleRemoveAllocation}
                                        disabled={!selectedCurrentId || loading}
                                        css={tw`text-xs px-2 py-1 bg-red-600 hover:bg-red-700`}
                                    >
                                        <FontAwesomeIcon icon={faTrash} css={tw`mr-1`} />
                                        Remove
                                    </Button>
                                </div>
                            </div>

                            <div css={tw`border border-gray-600 rounded overflow-hidden min-h-[200px] max-h-[400px]`}>
                                {currentAllocations.length === 0 ? (
                                    <div css={tw`p-4 text-center text-gray-400 text-sm`}>
                                        No allocations assigned. Add allocations from the right.
                                    </div>
                                ) : (
                                    <div css={tw`divide-y divide-gray-600 max-h-[400px] overflow-y-auto`}>
                                        {currentAllocations.map(allocation => (
                                            <div
                                                key={allocation.id}
                                                onClick={() =>
                                                    setSelectedCurrentId(prev =>
                                                        prev === allocation.id ? null : allocation.id,
                                                    )
                                                }
                                                css={tw`flex items-center justify-between p-3 cursor-pointer transition-colors hover:bg-gray-700`}
                                                style={{
                                                    backgroundColor:
                                                        selectedCurrentId === allocation.id ? '#374151' : undefined,
                                                }}
                                            >
                                                <div css={tw`flex items-center gap-3`}>
                                                    <input
                                                        type="radio"
                                                        checked={selectedCurrentId === allocation.id}
                                                        onChange={() => setSelectedCurrentId(allocation.id)}
                                                        css={tw`cursor-pointer`}
                                                        onClick={e => e.stopPropagation()}
                                                    />
                                                    <span css={tw`font-mono text-sm`}>
                                                        {allocation.getDisplayText()}
                                                    </span>
                                                    {allocation.id === server.allocationId && (
                                                        <span
                                                            css={tw`text-xs bg-blue-500 px-2 py-0.5 rounded flex items-center gap-1`}
                                                        >
                                                            <FontAwesomeIcon icon={faStar} css={tw`text-xs`} />
                                                            Primary
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Available Allocations */}
                        <div>
                            <div css={tw`flex items-center justify-between mb-2`}>
                                <Label>Available Allocations</Label>
                                <Button
                                    type="button"
                                    onClick={handleAddAllocation}
                                    disabled={!selectedAvailableId || !canAddMore || loading}
                                    css={tw`text-xs px-2 py-1`}
                                >
                                    <FontAwesomeIcon icon={faPlus} css={tw`mr-1`} />
                                    Add Selected
                                </Button>
                            </div>

                            <div
                                css={tw`border border-gray-600 rounded overflow-hidden min-h-[200px] max-h-[400px] overflow-y-auto`}
                            >
                                {loadingAvailable ? (
                                    <div css={tw`p-4 flex items-center justify-center`}>
                                        <Spinner size="small" />
                                    </div>
                                ) : availableAllocations.length === 0 ? (
                                    <div css={tw`p-4 text-center text-gray-400 text-sm`}>
                                        No available allocations on this node.
                                    </div>
                                ) : (
                                    <div css={tw`divide-y divide-gray-600`}>
                                        {availableAllocations.map(allocation => (
                                            <div
                                                key={allocation.id}
                                                onClick={() =>
                                                    setSelectedAvailableId(prev =>
                                                        prev === allocation.id ? null : allocation.id,
                                                    )
                                                }
                                                css={tw`flex items-center gap-3 p-3 cursor-pointer transition-colors hover:bg-gray-700`}
                                                style={{
                                                    backgroundColor:
                                                        selectedAvailableId === allocation.id ? '#374151' : undefined,
                                                }}
                                            >
                                                <input
                                                    type="radio"
                                                    checked={selectedAvailableId === allocation.id}
                                                    onChange={() => setSelectedAvailableId(allocation.id)}
                                                    css={tw`cursor-pointer`}
                                                    onClick={e => e.stopPropagation()}
                                                />
                                                <span css={tw`font-mono text-sm`}>{allocation.getDisplayText()}</span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {!canAddMore && allocationLimit > 0 && (
                                <p css={tw`text-xs text-yellow-400 mt-2`}>
                                    Allocation limit reached. Remove allocations or increase the limit.
                                </p>
                            )}
                        </div>
                    </div>

                    {/* Info Message */}
                    <div css={tw`text-xs text-gray-400 bg-gray-800 p-3 rounded mt-4`}>
                        <p>
                            💡 <strong>How to use:</strong> Click an allocation from the &quot;Available
                            Allocations&quot; list on the right and click &quot;Add Selected&quot; to add it
                            immediately. Click allocations in the &quot;Current Allocations&quot; list to select them,
                            then use &quot;Set Primary&quot; or &quot;Remove&quot;. Changes are saved automatically.
                        </p>
                    </div>
                </div>
            </Dialog>
        </>
    );
};
