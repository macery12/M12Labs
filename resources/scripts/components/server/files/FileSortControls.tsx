import { memo } from 'react';
import tw from 'twin.macro';
import { ServerContext } from '@/state/server';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faSortAmountDown, faSortAmountUp } from '@fortawesome/free-solid-svg-icons';
import type { SortField } from '@/state/server/files';
import { Button } from '@/elements/button/index';
import { useStoreState } from '@/state/hooks';

const sortOptions: Array<{ value: SortField; label: string }> = [
    { value: 'name', label: 'Name' },
    { value: 'modified', label: 'Modified' },
    { value: 'size', label: 'Size' },
    { value: 'type', label: 'Type' },
];

const FileSortControls = () => {
    const { colors } = useStoreState(state => state.theme.data!);
    const sortField = ServerContext.useStoreState(state => state.files.sortField);
    const sortDirection = ServerContext.useStoreState(state => state.files.sortDirection);
    const setSortField = ServerContext.useStoreActions(actions => actions.files.setSortField);
    const setSortDirection = ServerContext.useStoreActions(actions => actions.files.setSortDirection);

    const handleSortFieldChange = (field: SortField) => {
        if (field === sortField) {
            // Toggle direction if clicking the same field
            setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
        } else {
            // Set new field with default ascending direction
            setSortField(field);
            setSortDirection('asc');
        }
    };

    return (
        <div css={tw`flex items-center gap-2`}>
            <span css={tw`text-sm text-neutral-400`}>Sort by:</span>
            <div css={tw`flex gap-1`}>
                {sortOptions.map(option => (
                    <Button
                        key={option.value}
                        size={Button.Sizes.Small}
                        variant={sortField === option.value ? Button.Variants.Primary : Button.Variants.Secondary}
                        onClick={() => handleSortFieldChange(option.value)}
                        css={tw`px-3 py-1 text-xs`}
                        style={
                            sortField === option.value
                                ? { backgroundColor: colors.primary }
                                : { backgroundColor: colors.secondary }
                        }
                    >
                        {option.label}
                        {sortField === option.value && (
                            <FontAwesomeIcon
                                icon={sortDirection === 'asc' ? faSortAmountUp : faSortAmountDown}
                                css={tw`ml-1`}
                                size="xs"
                            />
                        )}
                    </Button>
                ))}
            </div>
        </div>
    );
};

export default memo(FileSortControls);
