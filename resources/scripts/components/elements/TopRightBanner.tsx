import { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTimes, faInfoCircle, faCheckCircle, faExclamationTriangle, faExclamationCircle } from '@fortawesome/free-solid-svg-icons';
import classNames from 'classnames';

interface TopRightBannerProps {
    id: number;
    title?: string;
    content: string;
    type: 'success' | 'info' | 'warning' | 'danger';
    link?: string;
    link_text?: string;
    onClose: () => void;
}

const typeConfig = {
    success: {
        bg: 'bg-green-600/90',
        border: 'border-green-500',
        icon: faCheckCircle,
        iconColor: 'text-green-200',
    },
    info: {
        bg: 'bg-blue-600/90',
        border: 'border-blue-500',
        icon: faInfoCircle,
        iconColor: 'text-blue-200',
    },
    warning: {
        bg: 'bg-yellow-600/90',
        border: 'border-yellow-500',
        icon: faExclamationTriangle,
        iconColor: 'text-yellow-200',
    },
    danger: {
        bg: 'bg-red-600/90',
        border: 'border-red-500',
        icon: faExclamationCircle,
        iconColor: 'text-red-200',
    },
};

export default ({ id, title, content, type, link, link_text, onClose }: TopRightBannerProps) => {
    const [isClosing, setIsClosing] = useState(false);
    const config = typeConfig[type];

    const handleClose = () => {
        setIsClosing(true);
        setTimeout(() => {
            onClose();
        }, 300);
    };

    return (
        <div
            className={classNames(
                'fixed top-0 right-0 z-50 transition-all duration-300 ease-in-out mt-4 mr-4',
                isClosing ? 'translate-x-full opacity-0' : 'translate-x-0 opacity-100'
            )}
            style={{
                width: '380px',
                maxWidth: 'calc(100vw - 2rem)',
            }}
        >
            <div
                className={classNames(
                    'rounded-lg shadow-2xl backdrop-blur-sm border-l-4 overflow-hidden',
                    config.bg,
                    config.border
                )}
            >
                <div className={'flex items-start p-4 gap-3'}>
                    {/* Icon */}
                    <div className={'flex-shrink-0 mt-0.5'}>
                        <FontAwesomeIcon 
                            icon={config.icon} 
                            className={classNames('w-6 h-6', config.iconColor)}
                        />
                    </div>

                    {/* Content */}
                    <div className={'flex-1 min-w-0'}>
                        {title && (
                            <h4 className={'text-white font-bold text-base mb-1'}>
                                {title}
                            </h4>
                        )}
                        <p className={'text-white/90 text-sm leading-relaxed'}>
                            {content}
                        </p>
                        {link && (
                            <a
                                href={link}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={'inline-block mt-2 text-sm font-semibold text-white hover:text-white/80 underline transition-colors'}
                            >
                                {link_text || 'Learn more'} →
                            </a>
                        )}
                    </div>

                    {/* Close button */}
                    <button
                        onClick={handleClose}
                        className={'flex-shrink-0 text-white/70 hover:text-white transition-colors'}
                        aria-label="Close banner"
                    >
                        <FontAwesomeIcon icon={faTimes} className={'w-5 h-5'} />
                    </button>
                </div>
            </div>
        </div>
    );
};
