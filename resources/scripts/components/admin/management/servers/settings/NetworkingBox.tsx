import { faNetworkWired, faStar, faTrash, faPlus } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useFormikContext } from 'formik';
import { useEffect, useState } from 'react';
import tw from 'twin.macro';

import getAllocations from '@/api/routes/admin/nodes/getAllocations';
import { useServerFromRoute } from '@/api/routes/admin/server';
import type { Values } from '@/api/routes/admin/servers/updateServer';
import AdminBox from '@/elements/AdminBox';
import Label from '@/elements/Label';
import type { Option } from '@/elements/SelectField';
import { AsyncSelectField } from '@/elements/SelectField';
import { Button } from '@/elements/button';

interface AllocationState {
    id: number;
    displayText: string;
    isPrimary: boolean;
    isMarkedForDeletion: boolean;
    isNew: boolean;
}

export default () => {
    const { isSubmitting, setFieldValue } = useFormikContext<Values>();
    const { data: server } = useServerFromRoute();
    const [allocations, setAllocations] = useState<AllocationState[]>([]);
    const [newAllocationsToAdd, setNewAllocationsToAdd] = useState<Option[]>([]);

    // Initialize allocations state from server data
    useEffect(() => {
        if (server?.relationships.allocations) {
            const initialAllocations = server.relationships.allocations.map(a => ({
                id: a.id,
                displayText: a.getDisplayText(),
                isPrimary: a.id === server.allocationId,
                isMarkedForDeletion: false,
                isNew: false,
            }));
            setAllocations(initialAllocations);
        }
    }, [server]);

    // Update Formik values whenever allocations state changes
    useEffect(() => {
        const primaryAllocation = allocations.find(a => a.isPrimary && !a.isMarkedForDeletion);
        const allocationsToRemove = allocations.filter(a => a.isMarkedForDeletion && !a.isNew).map(a => a.id);
        const allocationsToAdd = allocations.filter(a => a.isNew && !a.isMarkedForDeletion).map(a => a.id);

        // Set the primary allocation ID, or use the first active allocation if none is marked as primary
        if (primaryAllocation) {
            setFieldValue('allocationId', primaryAllocation.id);
        } else {
            const firstActive = allocations.find(a => !a.isMarkedForDeletion);
            if (firstActive) {
                setFieldValue('allocationId', firstActive.id);
            }
        }

        setFieldValue('removeAllocations', allocationsToRemove);
        setFieldValue('addAllocations', allocationsToAdd);
    }, [allocations, setFieldValue]);

    const loadOptions = async (inputValue: string, callback: (options: Option[]) => void) => {
        if (!server) {
            callback([] as Option[]);
            return;
        }

        const availableAllocations = await getAllocations(server.nodeId, { search: inputValue, server_id: '0' });

        callback(
            availableAllocations.map(a => {
                return { value: a.id.toString(), label: a.getDisplayText() };
            }),
        );
    };

    const handleSetPrimary = (allocationId: number) => {
        setAllocations(prev =>
            prev.map(a => ({
                ...a,
                isPrimary: a.id === allocationId,
            })),
        );
    };

    const handleToggleDelete = (allocationId: number) => {
        setAllocations(prev => {
            const allocation = prev.find(a => a.id === allocationId);
            if (!allocation) return prev;

            const newDeletionState = !allocation.isMarkedForDeletion;

            // If this was the primary allocation and we're marking it for deletion,
            // set the first non-deleted allocation as primary
            if (allocation.isPrimary && newDeletionState) {
                const newPrimary = prev.find(x => x.id !== allocationId && !x.isMarkedForDeletion);
                if (newPrimary) {
                    return prev.map(x => ({
                        ...x,
                        isPrimary: x.id === newPrimary.id,
                        isMarkedForDeletion: x.id === allocationId ? newDeletionState : x.isMarkedForDeletion,
                    }));
                }
            }

            return prev.map(a => (a.id === allocationId ? { ...a, isMarkedForDeletion: newDeletionState } : a));
        });
    };

    const handleAddAllocations = (selectedOptions: readonly Option[]) => {
        const newAllocs = selectedOptions.map(opt => ({
            id: parseInt(opt.value),
            displayText: opt.label,
            isPrimary: false,
            isMarkedForDeletion: false,
            isNew: true,
        }));

        setAllocations(prev => {
            // Check if this is the first allocation
            const hasActiveAllocations = prev.some(a => !a.isMarkedForDeletion);

            // If no active allocations exist, make the first new allocation primary
            if (!hasActiveAllocations && newAllocs.length > 0) {
                newAllocs[0].isPrimary = true;
            }

            return [...prev, ...newAllocs];
        });
        setNewAllocationsToAdd([]);
    };

    const activeAllocations = allocations.filter(a => !a.isMarkedForDeletion);
    const deletedAllocations = allocations.filter(a => a.isMarkedForDeletion);
    const allocationLimit = server?.featureLimits?.allocations || 0;
    const canAddMore = allocationLimit === 0 || activeAllocations.length < allocationLimit;

    return (
        <AdminBox icon={faNetworkWired} title={'Networking'} isLoading={isSubmitting}>
            <div css={tw`grid grid-cols-1 gap-4 lg:gap-6`}>
                <div>
                    <Label>Current Allocations</Label>
                    <div css={tw`space-y-2 mt-2`}>
                        {activeAllocations.length === 0 ? (
                            <p css={tw`text-sm text-gray-400`}>No allocations assigned. Add allocations below.</p>
                        ) : (
                            activeAllocations.map(allocation => (
                                <div
                                    key={allocation.id}
                                    css={tw`flex items-center justify-between p-3 bg-gray-700 rounded border-2 transition-colors`}
                                    style={{
                                        borderColor: allocation.isPrimary ? '#3b82f6' : 'transparent',
                                    }}
                                >
                                    <div css={tw`flex items-center gap-3 flex-1`}>
                                        <button
                                            type="button"
                                            onClick={() => handleSetPrimary(allocation.id)}
                                            css={tw`focus:outline-none`}
                                            title="Set as primary allocation"
                                        >
                                            <FontAwesomeIcon
                                                icon={faStar}
                                                css={tw`text-lg transition-colors`}
                                                style={{
                                                    color: allocation.isPrimary ? '#fbbf24' : '#6b7280',
                                                }}
                                            />
                                        </button>
                                        <span css={tw`text-sm font-mono`}>
                                            {allocation.displayText}
                                            {allocation.isNew && (
                                                <span css={tw`ml-2 text-xs text-green-400`}>(New)</span>
                                            )}
                                        </span>
                                        {allocation.isPrimary && (
                                            <span css={tw`ml-2 text-xs bg-blue-500 px-2 py-1 rounded`}>Primary</span>
                                        )}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => handleToggleDelete(allocation.id)}
                                        css={tw`px-3 py-1 text-red-400 hover:text-red-300 transition-colors focus:outline-none`}
                                        title="Remove allocation"
                                    >
                                        <FontAwesomeIcon icon={faTrash} />
                                    </button>
                                </div>
                            ))
                        )}
                    </div>

                    {allocationLimit > 0 && (
                        <p css={tw`text-xs text-gray-400 mt-2`}>
                            {activeAllocations.length} / {allocationLimit} allocations used
                        </p>
                    )}
                </div>

                {deletedAllocations.length > 0 && (
                    <div>
                        <Label>Allocations to Remove</Label>
                        <div css={tw`space-y-2 mt-2`}>
                            {deletedAllocations.map(allocation => (
                                <div
                                    key={allocation.id}
                                    css={tw`flex items-center justify-between p-3 bg-red-900 bg-opacity-20 rounded border border-red-500 border-opacity-30`}
                                >
                                    <span css={tw`text-sm font-mono text-red-300`}>
                                        {allocation.displayText}
                                        {allocation.isNew && <span css={tw`ml-2 text-xs`}>(Was New)</span>}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => handleToggleDelete(allocation.id)}
                                        css={tw`px-3 py-1 text-gray-400 hover:text-gray-300 text-sm transition-colors focus:outline-none`}
                                    >
                                        Undo
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div>
                    <AsyncSelectField
                        id={'addAllocationsSelect'}
                        name={'addAllocationsSelect'}
                        label={'Add New Allocations'}
                        loadOptions={loadOptions}
                        isMulti
                        value={newAllocationsToAdd}
                        onChange={(selected: readonly Option[]) => setNewAllocationsToAdd(selected || [])}
                        isDisabled={!canAddMore}
                    />
                    {!canAddMore && allocationLimit > 0 && (
                        <p css={tw`text-xs text-yellow-400 mt-1`}>
                            Allocation limit reached. Remove existing allocations or increase the limit to add more.
                        </p>
                    )}
                    {newAllocationsToAdd.length > 0 && (
                        <Button
                            type="button"
                            onClick={() => handleAddAllocations(newAllocationsToAdd)}
                            css={tw`mt-2`}
                            disabled={!canAddMore}
                        >
                            <FontAwesomeIcon icon={faPlus} css={tw`mr-2`} />
                            Add Selected Allocations
                        </Button>
                    )}
                </div>
            </div>
        </AdminBox>
    );
};
