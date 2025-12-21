import React from 'react';
import classNames from 'classnames';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTimes } from '@fortawesome/free-solid-svg-icons';

interface AlertProps {
    type: 'success' | 'info' | 'warning' | 'danger';
    className?: string;
    children: React.ReactNode;
    small?: boolean;
    onClose?: () => void;
}

export default ({ type, className, children, small, onClose }: AlertProps) => {
    return (
        <div
            className={classNames(
                'flex items-start border-l-8 text-gray-50 rounded-md shadow',
                small ? 'px-2 py-2 text-xs' : 'px-4 py-3',
                {
                    ['border-green-500 bg-green-500/25']: type === 'success',
                    ['border-blue-500 bg-blue-500/25']: type === 'info',
                    ['border-yellow-500 bg-yellow-500/25']: type === 'warning',
                    ['border-red-500 bg-red-500/25']: type === 'danger',
                },
                className,
            )}
        >
            <div className="flex-1">
                {children}
            </div>
            {onClose && (
                <button
                    onClick={onClose}
                    className={'ml-3 text-gray-400 hover:text-gray-200 transition-colors flex-shrink-0'}
                    aria-label="Close alert"
                >
                    <FontAwesomeIcon icon={faTimes} className={'w-4 h-4'} />
                </button>
            )}
        </div>
    );
};
