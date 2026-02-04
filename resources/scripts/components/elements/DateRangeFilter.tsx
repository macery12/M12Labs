import React from 'react';
import tw from 'twin.macro';

interface Props {
    startDate: string | null;
    endDate: string | null;
    onStartDateChange: (value: string | null) => void;
    onEndDateChange: (value: string | null) => void;
}

const DateRangeFilter: React.FC<Props> = ({ startDate, endDate, onStartDateChange, onEndDateChange }) => {
    return (
        <div css={tw`flex items-center gap-2`}>
            <label css={tw`text-sm text-gray-400 whitespace-nowrap`}>Date Range:</label>
            <input
                type="date"
                value={startDate || ''}
                onChange={(e) => onStartDateChange(e.target.value || null)}
                css={tw`bg-neutral-800 border border-neutral-700 text-white rounded px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none`}
            />
            <span css={tw`text-gray-400`}>to</span>
            <input
                type="date"
                value={endDate || ''}
                onChange={(e) => onEndDateChange(e.target.value || null)}
                css={tw`bg-neutral-800 border border-neutral-700 text-white rounded px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none`}
            />
        </div>
    );
};

export default DateRangeFilter;
