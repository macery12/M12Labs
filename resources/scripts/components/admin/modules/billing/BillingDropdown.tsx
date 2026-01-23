import { Menu, Transition } from '@headlessui/react';
import { ChevronDownIcon } from '@heroicons/react/solid';
import { NavLink } from 'react-router-dom';
import classNames from 'classnames';
import type { ComponentType, ReactNode } from 'react';
import { useStoreState } from '@/state/hooks';
import type { SiteTheme } from '@/state/theme';
import { useRef, useEffect, useState, useCallback } from 'react';

interface DropdownItemProps {
    to: string;
    name: string;
    icon?: ComponentType;
    children?: ReactNode;
}

const BillingDropdownItem = ({ to, name, icon: IconComponent, children, theme }: DropdownItemProps & { theme: SiteTheme }) => (
    <Menu.Item>
        {({ active }) => (
            <NavLink
                to={to}
                className="flex items-center px-4 py-2 text-sm transition-colors"
                style={
                    active
                        ? {
                              backgroundColor: theme.colors.headers,
                              color: theme.colors.primary,
                          }
                        : {
                              color: '#d1d5db', // neutral-300
                          }
                }
            >
                {IconComponent ? (
                    <IconComponent className="w-5 h-5 mr-2" />
                ) : (
                    children && <div className="w-5 h-5 mr-2">{children}</div>
                )}
                {name}
            </NavLink>
        )}
    </Menu.Item>
);

interface BillingDropdownProps {
    items: DropdownItemProps[];
}

export const BillingDropdown = ({ items }: BillingDropdownProps) => {
    const theme = useStoreState(state => state.theme.data!);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const [dropdownPosition, setDropdownPosition] = useState({ top: 0, left: 0 });

    const updatePosition = useCallback(() => {
        if (buttonRef.current) {
            const rect = buttonRef.current.getBoundingClientRect();
            setDropdownPosition({
                top: rect.bottom + window.scrollY,
                left: rect.left + window.scrollX,
            });
        }
    }, []);

    useEffect(() => {
        updatePosition();
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);

        return () => {
            window.removeEventListener('resize', updatePosition);
            window.removeEventListener('scroll', updatePosition, true);
        };
    }, [updatePosition]);

    return (
        <Menu as="div" className="relative inline-block h-full">
            {({ open }) => (
                <>
                    <Menu.Button
                        ref={buttonRef}
                        className={classNames(
                            'flex flex-row items-center h-full px-4 border-b text-base whitespace-nowrap transition-colors',
                            open ? 'border-primary text-primary' : 'border-transparent text-neutral-300',
                        )}
                        style={
                            open
                                ? {
                                      color: theme.colors.primary,
                                      borderColor: theme.colors.primary,
                                  }
                                : undefined
                        }
                        onClick={updatePosition}
                    >
                        <span>Billing</span>
                        <ChevronDownIcon
                            className={classNames('w-4 h-4 ml-2 transition-transform', open && 'rotate-180')}
                        />
                    </Menu.Button>
                    <Transition
                        enter="transition duration-100 ease-out"
                        enterFrom="transform scale-95 opacity-0"
                        enterTo="transform scale-100 opacity-100"
                        leave="transition duration-75 ease-out"
                        leaveFrom="transform scale-100 opacity-100"
                        leaveTo="transform scale-95 opacity-0"
                    >
                        <Menu.Items
                            className="fixed w-56 origin-top-left rounded shadow-lg focus:outline-none"
                            style={{
                                backgroundColor: theme.colors.sidebar,
                                borderColor: theme.colors.headers,
                                borderWidth: '1px',
                                zIndex: 9999,
                                top: `${dropdownPosition.top}px`,
                                left: `${dropdownPosition.left}px`,
                            }}
                        >
                            <div className="py-1">{items.map(item => <BillingDropdownItem key={item.to} {...item} theme={theme} />)}</div>
                        </Menu.Items>
                    </Transition>
                </>
            )}
        </Menu>
    );
};
