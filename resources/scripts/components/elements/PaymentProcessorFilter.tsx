import React from 'react';
import tw from 'twin.macro';

export type PaymentProcessor = 'stripe' | 'mollie' | 'paypal';

interface Props {
    value: PaymentProcessor | null;
    onChange: (processor: PaymentProcessor | null) => void;
}

const PaymentProcessorFilter: React.FC<Props> = ({ value, onChange }) => {
    return (
        <div css={tw`flex items-center gap-2`}>
            <label css={tw`text-sm text-gray-400`}>Payment Provider:</label>
            <select
                value={value || ''}
                onChange={(e) => onChange(e.target.value === '' ? null : e.target.value as PaymentProcessor)}
                css={tw`bg-neutral-800 border border-neutral-700 text-white rounded px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none`}
            >
                <option value="">All Providers</option>
                <option value="stripe">Stripe</option>
                <option value="mollie">Mollie</option>
                <option value="paypal">PayPal</option>
            </select>
        </div>
    );
};

export default PaymentProcessorFilter;
