import { useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTimes } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import classNames from 'classnames';

interface SlideOutAlertProps {
    id: number;
    title?: string;
    content: string;
    type: 'success' | 'info' | 'warning' | 'danger';
    link?: string;
    link_text?: string;
    onClose: () => void;
    index: number;
}

const typeColors = {
    success: 'border-green-500 bg-green-500/20',
    info: 'border-blue-500 bg-blue-500/20',
    warning: 'border-yellow-500 bg-yellow-500/20',
    danger: 'border-red-500 bg-red-500/20',
};

const typeTextColors = {
    success: 'text-green-300',
    info: 'text-blue-300',
    warning: 'text-yellow-300',
    danger: 'text-red-300',
};

export default ({ id: _id, title, content, type, link, link_text, onClose, index }: SlideOutAlertProps) => {
    const [isClosing, setIsClosing] = useState(false);

    const handleClose = () => {
        setIsClosing(true);
        setTimeout(() => {
            onClose(); // This will call dismissAlert in parent component
        }, 300); // Match animation duration
    };

    // Calculate position based on index - stack them vertically
    const topPosition = 4 + index * 7; // 4rem base + 7rem spacing between cards

    return (
        <div
            className={classNames(
                'fixed right-0 z-50 transition-all duration-300 ease-in-out',
                isClosing ? 'translate-x-full opacity-0' : 'translate-x-0 opacity-100',
            )}
            style={{
                top: `${topPosition}rem`,
                width: '400px',
                maxWidth: '90vw',
            }}
        >
            <div
                className={classNames('mr-4 rounded-lg border-l-4 shadow-lg backdrop-blur-sm', typeColors[type])}
                css={tw`bg-neutral-800/95`}
            >
                {/* Header */}
                <div className={'flex items-start justify-between p-4 pb-2'}>
                    <div className={'flex-1'}>
                        {title && (
                            <h3 className={classNames('mb-1 text-lg font-semibold', typeTextColors[type])}>{title}</h3>
                        )}
                    </div>
                    <button
                        onClick={handleClose}
                        className={'ml-3 flex-shrink-0 text-gray-400 transition-colors hover:text-gray-200'}
                        aria-label="Close notification"
                    >
                        <FontAwesomeIcon icon={faTimes} className={'h-5 w-5'} />
                    </button>
                </div>

                {/* Separator */}
                {title && <div className={'mx-4 border-t border-gray-600/50'} />}

                {/* Content */}
                <div className={'p-4 pt-3'}>
                    <div className={'whitespace-pre-wrap text-sm leading-relaxed text-gray-300'}>{content}</div>
                    {link && (
                        <a
                            href={link}
                            target="_blank"
                            rel="noopener noreferrer"
                            className={classNames(
                                'mt-3 inline-block text-sm font-medium transition-colors hover:underline',
                                typeTextColors[type],
                            )}
                        >
                            {link_text || 'Learn more'} →
                        </a>
                    )}
                </div>
            </div>
        </div>
    );
};
