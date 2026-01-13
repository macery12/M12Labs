import { faNetworkWired, faPlus, faTrash } from '@fortawesome/free-solid-svg-icons';
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
    isNew: boolean;
}

export default () => {
    const { isSubmitting, setFieldValue } = useFormikContext<Values>();
    const { data: server } = useServerFromRoute();
    const [allocations, setAllocations] = useState<AllocationState[]>([]);
    const [selectedAllocationId, setSelectedAllocationId] = useState<number | null>(null);
    const [newAllocationsToAdd, setNewAllocationsToAdd] = useState<Option[]>([]);

    // Initialize allocations state from server data
    useEffect(() => {
        if (server?.relationships.allocations) {
            const initialAllocations = server.relationships.allocations.map(a => ({
                id: a.id,
                displayText: a.getDisplayText(),
                isPrimary: a.id === server.allocationId,
                isNew: false,
            }));
            setAllocations(initialAllocations);
        }
    }, [server]);

    // Update Formik values whenever allocations state changes
    useEffect(() => {
        const primaryAllocation = allocations.find(a => a.isPrimary);
        const existingIds = server?.relationships.allocations?.map(a => a.id) || [];
        const allocationsToAdd = allocations.filter(a => a.isNew && !existingIds.includes(a.id)).map(a => a.id);
        const allocationsToRemove = existingIds.filter(id => !allocations.find(a => a.id === id));

        if (primaryAllocation) {
            setFieldValue('allocationId', primaryAllocation.id);
        }
        setFieldValue('addAllocations', allocationsToAdd);
        setFieldValue('removeAllocations', allocationsToRemove);
    }, [allocations, server, setFieldValue]);

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

    const handleSelectAllocation = (allocationId: number) => {
        setSelectedAllocationId(prev => (prev === allocationId ? null : allocationId));
    };

    const handleSetPrimary = () => {
        if (selectedAllocationId === null) return;

        setAllocations(prev =>
            prev.map(a => ({
                ...a,
                isPrimary: a.id === selectedAllocationId,
            })),
        );
    };

    const handleRemoveSelected = () => {
        if (selectedAllocationId === null) return;

        setAllocations(prev => {
            const newAllocations = prev.filter(a => a.id !== selectedAllocationId);

            // If we removed the primary, set the first allocation as primary
            if (prev.find(a => a.id === selectedAllocationId)?.isPrimary && newAllocations.length > 0) {
                newAllocations[0].isPrimary = true;
            }

            return newAllocations;
        });
        setSelectedAllocationId(null);
    };

    const handleAddAllocations = () => {
        if (newAllocationsToAdd.length === 0) return;

        const newAllocs = newAllocationsToAdd.map(opt => ({
            id: parseInt(opt.value),
            displayText: opt.label,
            isPrimary: allocations.length === 0,
            isNew: true,
        }));

        setAllocations(prev => [...prev, ...newAllocs]);
        setNewAllocationsToAdd([]);

        // Scroll to the allocation list to show the newly added items
        const allocationList = document.querySelector('[data-allocation-list]');
        if (allocationList) {
            allocationList.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    };

    const allocationLimit = server?.featureLimits?.allocations || 0;
    const canAddMore = allocationLimit === 0 || allocations.length < allocationLimit;

    return (
        <AdminBox icon={faNetworkWired} title={'Networking'} isLoading={isSubmitting}>
            <div css={tw`grid grid-cols-1 gap-4 lg:gap-6`}>
                {/* List Container */}
                <div>
                    <div css={tw`flex items-center justify-between mb-2`}>
                        <Label>Allocations</Label>
                        <div css={tw`flex gap-2`}>
                            <Button
                                type="button"
                                onClick={handleSetPrimary}
                                disabled={selectedAllocationId === null}
                                title="Set selected as primary"
                                css={tw`text-xs px-2 py-1`}
                            >
                                Set Primary
                            </Button>
                            <Button
                                type="button"
                                onClick={handleRemoveSelected}
                                disabled={selectedAllocationId === null}
                                title="Remove selected allocation"
                                css={tw`text-xs px-2 py-1 bg-red-600 hover:bg-red-700`}
                            >
                                <FontAwesomeIcon icon={faTrash} css={tw`mr-1`} />
                                Remove
                            </Button>
                        </div>
                    </div>

                    {/* Allocation List */}
                    <div css={tw`border border-gray-600 rounded overflow-hidden`} data-allocation-list>
                        {allocations.length === 0 ? (
                            <div css={tw`p-4 text-center text-gray-400 text-sm`}>
                                No allocations assigned. Add allocations below.
                            </div>
                        ) : (
                            <div css={tw`divide-y divide-gray-600`}>
                                {allocations.map(allocation => (
                                    <div
                                        key={allocation.id}
                                        onClick={() => handleSelectAllocation(allocation.id)}
                                        css={tw`flex items-center justify-between p-3 cursor-pointer transition-colors hover:bg-gray-700`}
                                        style={{
                                            backgroundColor:
                                                selectedAllocationId === allocation.id ? '#374151' : undefined,
                                        }}
                                    >
                                        <div css={tw`flex items-center gap-3`}>
                                            <input
                                                type="radio"
                                                checked={selectedAllocationId === allocation.id}
                                                onChange={() => handleSelectAllocation(allocation.id)}
                                                css={tw`cursor-pointer`}
                                                onClick={e => e.stopPropagation()}
                                            />
                                            <span css={tw`font-mono text-sm`}>{allocation.displayText}</span>
                                            {allocation.isPrimary && (
                                                <span css={tw`text-xs bg-blue-500 px-2 py-0.5 rounded`}>Primary</span>
                                            )}
                                            {allocation.isNew && (
                                                <span css={tw`text-xs bg-green-500 px-2 py-0.5 rounded animate-pulse`}>
                                                    New
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {allocationLimit > 0 && (
                        <p css={tw`text-xs text-gray-400 mt-2`}>
                            {allocations.length} / {allocationLimit} allocations used
                        </p>
                    )}
                </div>

                {/* Add Allocations Section */}
                <div>
                    <div css={tw`flex items-end gap-2`}>
                        <div css={tw`flex-1`}>
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
                        </div>
                        <Button
                            type="button"
                            onClick={handleAddAllocations}
                            disabled={newAllocationsToAdd.length === 0 || !canAddMore}
                        >
                            <FontAwesomeIcon icon={faPlus} css={tw`mr-2`} />
                            Add
                        </Button>
                    </div>
                    {!canAddMore && allocationLimit > 0 && (
                        <p css={tw`text-xs text-yellow-400 mt-1`}>
                            Allocation limit reached. Remove existing allocations or increase the limit to add more.
                        </p>
                    )}
                </div>

                {/* Info Message */}
                <div css={tw`text-xs text-gray-400 bg-gray-800 p-3 rounded`}>
                    <p>
                        💡 <strong>Tip:</strong> Select allocations from the dropdown above and click &quot;Add&quot; to
                        add them to the list. Click an allocation in the list to select it, then use &quot;Set
                        Primary&quot; or &quot;Remove&quot;. All changes are staged locally - click &quot;Save
                        Changes&quot; at the bottom to apply them.
                    </p>
                </div>
            </div>
        </AdminBox>
    );
};
