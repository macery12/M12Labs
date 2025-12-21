import * as React from 'react';
import { Dialog, RenderDialogProps } from './';
import { Button } from '@/elements/button/index';

type ConfirmationProps = Omit<RenderDialogProps, 'description' | 'children'> & {
    children: React.ReactNode;
    confirm?: string | undefined;
    buttonType?: 'success' | 'info' | 'warning' | 'danger';
    onConfirmed: (e: React.MouseEvent<HTMLButtonElement, MouseEvent>) => void;
};

const getTitleBackgroundColor = (type?: 'success' | 'info' | 'warning' | 'danger') => {
    switch (type) {
        case 'success':
            return 'bg-green-600';
        case 'warning':
            return 'bg-yellow-600';
        case 'danger':
            return 'bg-red-600';
        case 'info':
        default:
            return 'bg-blue-600';
    }
};

export default ({ confirm = 'Okay', children, onConfirmed, buttonType, ...props }: ConfirmationProps) => {
    // Create a custom header with colored background
    const titleWithBackground = props.title ? (
        <div className={`-mx-6 -mt-6 px-6 py-4 mb-4 ${getTitleBackgroundColor(buttonType)}`}>
            <h2 className="text-xl font-bold text-white">{props.title}</h2>
        </div>
    ) : undefined;

    return (
        <Dialog {...props} title={titleWithBackground as any} description={typeof children === 'string' ? children : undefined}>
            {typeof children !== 'string' && children}
            <Dialog.Footer>
                <Button.Text onClick={props.onClose}>Cancel</Button.Text>
                {(!buttonType || buttonType === 'info') && <Button.Info onClick={onConfirmed}>{confirm}</Button.Info>}
                {buttonType === 'danger' && <Button.Danger onClick={onConfirmed}>{confirm}</Button.Danger>}
                {buttonType === 'warning' && <Button.Warn onClick={onConfirmed}>{confirm}</Button.Warn>}
                {buttonType === 'success' && <Button onClick={onConfirmed}>{confirm}</Button>}
            </Dialog.Footer>
        </Dialog>
    );
};
