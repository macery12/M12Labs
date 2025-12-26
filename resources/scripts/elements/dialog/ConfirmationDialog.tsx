import * as React from 'react';
import { Dialog, RenderDialogProps } from './';
import { Button } from '@/elements/button/index';

type ConfirmationProps = Omit<RenderDialogProps, 'description' | 'children'> & {
    children: React.ReactNode;
    confirm?: string | undefined;
    buttonType?: 'success' | 'info' | 'warning' | 'danger';
    onConfirmed: (e: React.MouseEvent<HTMLButtonElement, MouseEvent>) => void;
};

export default ({ confirm = 'Okay', children, onConfirmed, buttonType, ...props }: ConfirmationProps) => {
    // Use panel theme colors for title and description
    const titleElement = props.title ? (
        <div className="-mx-6 -mt-6 mb-6 border-b border-zinc-600 bg-zinc-800 px-6 py-4">
            <h2 className="text-xl font-bold text-slate-50">{props.title}</h2>
        </div>
    ) : undefined;

    return (
        <Dialog
            {...props}
            title={titleElement as any}
            description={typeof children === 'string' ? children : undefined}
        >
            {typeof children !== 'string' && <div className="text-slate-300">{children}</div>}
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
