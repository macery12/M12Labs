import React from 'react';
import { faCreditCard } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import tw from 'twin.macro';

interface Props {
    processor: 'stripe' | 'mollie' | 'paypal';
    size?: 'small' | 'medium' | 'large';
}

const PaymentProcessorBadge: React.FC<Props> = ({ processor, size = 'medium' }) => {
    const getProcessorInfo = () => {
        switch (processor) {
            case 'stripe':
                return {
                    name: 'Stripe',
                    emoji: '💳',
                    color: 'bg-purple-600',
                    textColor: 'text-white',
                };
            case 'mollie':
                return {
                    name: 'Mollie',
                    emoji: '💶',
                    color: 'bg-blue-500',
                    textColor: 'text-white',
                };
            case 'paypal':
                return {
                    name: 'PayPal',
                    emoji: '🅿️',
                    color: 'bg-cyan-500',
                    textColor: 'text-white',
                };
            default:
                return {
                    name: 'Unknown',
                    emoji: '❓',
                    color: 'bg-gray-500',
                    textColor: 'text-white',
                };
        }
    };

    const getSizeClasses = () => {
        switch (size) {
            case 'small':
                return 'px-2 py-0.5 text-xs';
            case 'large':
                return 'px-4 py-2 text-base';
            case 'medium':
            default:
                return 'px-3 py-1 text-sm';
        }
    };

    const info = getProcessorInfo();

    return (
        <span
            css={tw`inline-flex items-center gap-1.5 rounded-full font-medium shadow-sm`}
            className={`${info.color} ${info.textColor} ${getSizeClasses()}`}
        >
            <span>{info.emoji}</span>
            {info.name}
        </span>
    );
};

export default PaymentProcessorBadge;
