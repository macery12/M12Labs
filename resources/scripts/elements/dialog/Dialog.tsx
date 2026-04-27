import { Fragment, useRef, useState } from 'react';
import * as React from 'react';
import { Dialog as HDialog, Transition } from '@headlessui/react';
import { Button } from '@/elements/button/index';
import { DialogContext, IconPosition, RenderDialogProps } from './';
import { ReplyIcon, XIcon } from '@heroicons/react/outline';
import classNames from 'classnames';
import styles from './style.module.css';

const sizeMap = {
    sm: 'max-w-md',
    md: 'max-w-xl',
    lg: 'max-w-4xl',
    xl: 'max-w-7xl',
};

export default ({
    open,
    title,
    description,
    onClose,
    hideCloseIcon,
    preventExternalClose,
    children,
    subDialog,
    size = 'md',
}: RenderDialogProps) => {
    const container = useRef<HTMLDivElement>(null);
    const [icon, setIcon] = useState<React.ReactNode>();
    const [footer, setFooter] = useState<React.ReactNode>();
    const [iconPosition, setIconPosition] = useState<IconPosition>('title');
    const [down, setDown] = useState(false);

    const onContainerClick = (down: boolean, e: React.MouseEvent<HTMLDivElement>): void => {
        if (e.target instanceof HTMLElement && container.current?.isSameNode(e.target)) {
            setDown(down);
        }
    };

    const onDialogClose = (): void => {
        if (!preventExternalClose) {
            onClose();
        }
    };

    return (
        <Transition show={open} as={Fragment}>
            <HDialog static open={open} onClose={onDialogClose}>
                <DialogContext.Provider value={{ setIcon, setFooter, setIconPosition }}>
                    {/* Backdrop */}
                    <Transition.Child
                        as={Fragment}
                        enter="transition-opacity ease-out duration-150"
                        enterFrom="opacity-0"
                        enterTo="opacity-100"
                        leave="transition-opacity ease-in duration-150"
                        leaveFrom="opacity-100"
                        leaveTo="opacity-0"
                    >
                        <div className="fixed inset-0 z-40 bg-slate-900/50" />
                    </Transition.Child>
                    <div className="fixed inset-0 z-50 overflow-y-auto">
                        <div
                            ref={container}
                            className={styles.container}
                            onMouseDown={e => onContainerClick(true, e)}
                            onMouseUp={e => onContainerClick(false, e)}
                        >
                            {/* Panel */}
                            <Transition.Child
                                as={Fragment}
                                enter="transition ease-out duration-150"
                                enterFrom="opacity-0 scale-75"
                                enterTo="opacity-100 scale-100"
                                leave="transition ease-in duration-150"
                                leaveFrom="opacity-100 scale-100"
                                leaveTo="opacity-0 scale-75"
                            >
                                <HDialog.Panel
                                    className={classNames(
                                        styles.panel,
                                        sizeMap[size],
                                        'transition-transform duration-75',
                                        down && 'scale-95',
                                    )}
                                >
                                    <div className="flex overflow-y-auto p-6 pb-0">
                                        {iconPosition === 'container' && icon}
                                        <div className="max-h-[70vh] min-w-0 flex-1">
                                            <div className="flex items-center">
                                                {iconPosition !== 'container' && icon}
                                                <div>
                                                    {title && (
                                                        <HDialog.Title className={styles.title}>{title}</HDialog.Title>
                                                    )}
                                                    {description && (
                                                        <p className={'text-sm italic text-gray-400'}>{description}</p>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="invisible h-6" />
                                            {children}
                                            <div className="invisible h-6" />
                                        </div>
                                    </div>
                                    {footer}
                                    {!hideCloseIcon && (
                                        <div className="absolute right-0 top-0 m-4">
                                            <Button.Text
                                                size={Button.Sizes.Small}
                                                shape={Button.Shapes.IconSquare}
                                                onClick={onClose}
                                                className="group"
                                            >
                                                {subDialog ? (
                                                    <ReplyIcon className={styles.close_icon} />
                                                ) : (
                                                    <XIcon className={styles.close_icon} />
                                                )}
                                            </Button.Text>
                                        </div>
                                    )}
                                </HDialog.Panel>
                            </Transition.Child>
                        </div>
                    </div>
                </DialogContext.Provider>
            </HDialog>
        </Transition>
    );
};
