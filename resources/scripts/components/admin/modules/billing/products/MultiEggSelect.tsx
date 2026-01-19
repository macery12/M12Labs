import { useField } from 'formik';
import { useEffect, useState } from 'react';
import tw from 'twin.macro';

import type { WithRelationships } from '@/api/routes/admin';
import type { Egg } from '@/api/routes/admin/egg';
import { searchEggs } from '@/api/routes/admin/egg';
import Label from '@/elements/Label';
import AdminCheckbox from '@/elements/AdminCheckbox';
import { useStoreState } from '@/state/hooks';

interface Props {
    nestId?: number;
    selectedEggIds?: number[];
    onEggSelectionChange: (eggIds: number[]) => void;
}

export default ({ nestId, selectedEggIds = [], onEggSelectionChange }: Props) => {
    const [, , { setValue: setEggIdValue, setTouched: setEggIdTouched }] = useField<number>('eggId');
    const [, , { setValue: setAllowedEggsValue, setTouched: setAllowedEggsTouched }] =
        useField<number[]>('allowedEggs');
    const [eggs, setEggs] = useState<WithRelationships<Egg, 'variables'>[] | undefined>(undefined);
    const [selected, setSelected] = useState<number[]>(selectedEggIds);
    const theme = useStoreState(state => state.theme.data!);

    useEffect(() => {
        if (!nestId) {
            setEggs(undefined);
            return;
        }

        searchEggs(nestId, {})
            .then(_eggs => {
                setEggs(_eggs);

                // If we have previously selected eggs, use them
                if (selectedEggIds.length > 0) {
                    setSelected(selectedEggIds);
                    // Set the primary egg (first in the list)
                    setEggIdValue(selectedEggIds[0]);
                    setEggIdTouched(true);
                    setAllowedEggsValue(selectedEggIds);
                    setAllowedEggsTouched(true);
                } else if (_eggs.length > 0) {
                    // Default to first egg if none selected
                    const defaultEggId = _eggs[0].id;
                    setSelected([defaultEggId]);
                    setEggIdValue(defaultEggId);
                    setEggIdTouched(true);
                    setAllowedEggsValue([defaultEggId]);
                    setAllowedEggsTouched(true);
                    onEggSelectionChange([defaultEggId]);
                }
            })
            .catch(error => console.error(error));
    }, [nestId]);

    // Sync selected eggs when selectedEggIds prop changes (e.g., after form reinitialization)
    // Note: We intentionally don't include 'selected' in dependencies to avoid infinite loops.
    // We only update when selectedEggIds changes externally, using selected for comparison only.
    // Array order matters: the first egg is the primary egg for the category.
    useEffect(() => {
        // Compare arrays: check length and all elements in order
        const arraysEqual = selected.length === selectedEggIds.length && 
            selected.every((id, index) => id === selectedEggIds[index]);
        
        if (!arraysEqual) {
            setSelected(selectedEggIds);
            if (selectedEggIds.length > 0) {
                setEggIdValue(selectedEggIds[0]);
                setEggIdTouched(true);
            }
            setAllowedEggsValue(selectedEggIds);
            setAllowedEggsTouched(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedEggIds]);

    const handleEggToggle = (eggId: number, checked: boolean) => {
        let newSelected: number[];

        if (checked) {
            // Add egg to selection
            newSelected = [...selected, eggId];
        } else {
            // Remove egg from selection (but ensure at least one remains)
            newSelected = selected.filter(id => id !== eggId);
            if (newSelected.length === 0) {
                // Don't allow deselecting the last egg
                return;
            }
        }

        setSelected(newSelected);

        // Update form values
        setEggIdValue(newSelected[0]); // Primary egg is the first one
        setEggIdTouched(true);
        setAllowedEggsValue(newSelected);
        setAllowedEggsTouched(true);

        onEggSelectionChange(newSelected);
    };

    return (
        <>
            <Label>Allowed Eggs</Label>
            <p css={tw`text-xs text-neutral-400 mb-3`}>
                Select one or more eggs that users can choose from in this category. The first selected egg will be the
                default.
            </p>
            <div css={tw`space-y-2`}>
                {!eggs ? (
                    <p css={tw`text-sm text-neutral-400`}>Loading eggs...</p>
                ) : eggs.length === 0 ? (
                    <p css={tw`text-sm text-neutral-400`}>No eggs available in this nest.</p>
                ) : (
                    eggs.map(egg => (
                        <div
                            key={egg.id}
                            css={tw`flex items-center space-x-2 p-3 rounded shadow-md transition duration-200`}
                            style={{
                                backgroundColor: selected.includes(egg.id)
                                    ? theme.colors.headers
                                    : theme.colors.secondary,
                            }}
                            className={'hover:brightness-110'}
                        >
                            <AdminCheckbox
                                name={`egg-${egg.id}`}
                                checked={selected.includes(egg.id)}
                                onChange={e => handleEggToggle(egg.id, e.target.checked)}
                            />
                            <label htmlFor={`egg-${egg.id}`} css={tw`flex-1 cursor-pointer text-sm`}>
                                {egg.name}
                                {selected[0] === egg.id && (
                                    <span css={tw`ml-2 text-xs`} style={{ color: theme.colors.primary }}>
                                        (Primary)
                                    </span>
                                )}
                            </label>
                        </div>
                    ))
                )}
            </div>
            {selected.length > 1 && (
                <p css={tw`text-xs text-neutral-400 mt-2`}>
                    {selected.length} eggs selected. Users will be able to choose between them during checkout.
                </p>
            )}
        </>
    );
};
