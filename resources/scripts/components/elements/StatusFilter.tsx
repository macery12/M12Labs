import React from 'react';
import tw from 'twin.macro';
import { OrderStatus } from '@/api/routes/account/billing/orders/types';

interface Props {
    value: OrderStatus | null;
    onChange: (status: OrderStatus | null) => void;
}

const StatusFilter: React.FC<Props> = ({ value, onChange }) => {
    return (
        <div css={tw`flex items-center gap-2`}>
            <label css={tw`text-sm text-gray-400 whitespace-nowrap`}>Status:</label>
            <select
                value={value || ''}
                onChange={(e) => onChange(e.target.value === '' ? null : e.target.value as OrderStatus)}
                css={tw`bg-neutral-800 border border-neutral-700 text-white rounded px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none`}
            >
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="processed">Processed</option>
                <option value="failed">Failed</option>
                <option value="expired">Expired</option>
            </select>
        </div>
    );
};

export default StatusFilter;
