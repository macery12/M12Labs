import { useState } from 'react';
import AlertComponent from '@/components/AlertComponent';

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

export default ({ id, title, content, type, link, link_text, onClose, index }: SlideOutAlertProps) => {
    const [isClosing, setIsClosing] = useState(false);

    const handleClose = () => {
        setIsClosing(true);
        setTimeout(() => {
            onClose();
        }, 300);
    };

    // Calculate position based on index - stack them vertically
    const topPosition = 1 + index * 7; // Start at 1rem with 7rem spacing

    // Convert 'danger' to 'error' for AlertComponent
    const alertType = type === 'danger' ? 'error' : type;

    // Build message with link if provided
    const messageContent = (
        <>
            {content}
            {link && (
                <>
                    <br />
                    <a
                        href={link}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mt-2 inline-block font-medium underline hover:no-underline"
                    >
                        {link_text || 'Learn more'} →
                    </a>
                </>
            )}
        </>
    );

    return (
        <div
            className={`fixed right-4 z-50 transition-all duration-300 ${
                isClosing ? 'translate-x-full opacity-0' : 'translate-x-0 opacity-100'
            }`}
            style={{
                top: `${topPosition}rem`,
                maxWidth: '420px',
                width: '100%',
            }}
        >
            <AlertComponent
                alert={{
                    id: id.toString(),
                    type: alertType as 'success' | 'error' | 'info' | 'warning',
                    message: typeof messageContent === 'string' ? messageContent : content,
                    title: title,
                    dismissible: true,
                }}
                onDismiss={handleClose}
            />
        </div>
    );
};
