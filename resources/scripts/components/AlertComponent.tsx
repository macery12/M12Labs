import React, { useState } from 'react';
import classNames from 'classnames';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faTimes,
    faCheckCircle,
    faInfoCircle,
    faExclamationTriangle,
    faExclamationCircle,
} from '@fortawesome/free-solid-svg-icons';
import { Alert as AlertType, AlertAction } from '@/contexts/AlertContext';

interface AlertComponentProps {
    alert: AlertType;
    onDismiss?: (id: string) => void;
    className?: string;
}

const typeConfig = {
    success: {
        icon: faCheckCircle,
        borderColor: 'border-green-500',
        bgColor: 'bg-green-500/30',
        textColor: 'text-green-50',
        iconColor: 'text-green-400',
        buttonBg: 'bg-green-600 hover:bg-green-700',
    },
    error: {
        icon: faExclamationCircle,
        borderColor: 'border-red-500',
        bgColor: 'bg-red-500/30',
        textColor: 'text-red-50',
        iconColor: 'text-red-400',
        buttonBg: 'bg-red-600 hover:bg-red-700',
    },
    info: {
        icon: faInfoCircle,
        borderColor: 'border-blue-500',
        bgColor: 'bg-blue-500/30',
        textColor: 'text-blue-50',
        iconColor: 'text-blue-400',
        buttonBg: 'bg-blue-600 hover:bg-blue-700',
    },
    warning: {
        icon: faExclamationTriangle,
        borderColor: 'border-yellow-500',
        bgColor: 'bg-yellow-500/30',
        textColor: 'text-yellow-50',
        iconColor: 'text-yellow-400',
        buttonBg: 'bg-yellow-600 hover:bg-yellow-700',
    },
};

export const AlertComponent: React.FC<AlertComponentProps> = ({ alert, onDismiss, className }) => {
    const [isClosing, setIsClosing] = useState(false);
    const config = typeConfig[alert.type];

    const handleDismiss = () => {
        if (!alert.dismissible) return;

        setIsClosing(true);
        setTimeout(() => {
            if (onDismiss) {
                onDismiss(alert.id);
            }
        }, 300); // Match animation duration
    };

    return (
        <div
            className={classNames(
                'flex items-start rounded-md border-l-8 shadow-lg transition-all duration-300',
                'px-4 py-3',
                config.borderColor,
                config.bgColor,
                config.textColor,
                isClosing ? 'scale-95 opacity-0' : 'scale-100 opacity-100',
                className,
            )}
            role="alert"
            aria-live="polite"
            aria-atomic="true"
        >
            {/* Icon */}
            <div className={classNames('mr-3 flex-shrink-0', config.iconColor)}>
                <FontAwesomeIcon icon={config.icon} className={'h-5 w-5'} />
            </div>

            {/* Content */}
            <div className="flex-1">
                {alert.title && <div className="mb-1 font-semibold">{alert.title}</div>}
                <div className="text-sm">{alert.message}</div>

                {/* Actions */}
                {alert.actions && alert.actions.length > 0 && (
                    <div className="mt-3 flex space-x-2">
                        {alert.actions.map((action: AlertAction, index: number) => (
                            <button
                                key={index}
                                onClick={() => {
                                    action.onClick();
                                    if (alert.dismissible) {
                                        handleDismiss();
                                    }
                                }}
                                className={classNames(
                                    'rounded px-3 py-1 text-xs font-medium text-white transition-colors',
                                    action.variant === 'secondary' ? 'bg-gray-600 hover:bg-gray-700' : config.buttonBg,
                                )}
                            >
                                {action.label}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {/* Close button */}
            {alert.dismissible && (
                <button
                    onClick={handleDismiss}
                    className={'ml-3 flex-shrink-0 text-gray-400 transition-colors hover:text-gray-200'}
                    aria-label="Dismiss alert"
                >
                    <FontAwesomeIcon icon={faTimes} className={'h-4 w-4'} />
                </button>
            )}
        </div>
    );
};

export default AlertComponent;
