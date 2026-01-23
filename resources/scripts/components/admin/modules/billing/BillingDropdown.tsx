import { Menu, Transition } from '@headlessui/react';
import { ChevronDownIcon } from '@heroicons/react/solid';
import { NavLink } from 'react-router-dom';
import classNames from 'classnames';
import type { ComponentType, ReactNode } from 'react';
import { useStoreState } from '@/state/hooks';

interface DropdownItemProps {
    to: string;
    name: string;
    icon?: ComponentType;
    children?: ReactNode;
}

const BillingDropdownItem = ({ to, name, icon: IconComponent, children }: DropdownItemProps) => (
    <Menu.Item>
        {({ active }) => (
            <NavLink
                to={to}
                className={classNames(
                    'flex items-center px-4 py-2 text-sm transition-colors',
                    active ? 'bg-neutral-700 text-white' : 'text-neutral-300',
                )}
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

    return (
        <Menu as="div" className="relative inline-block h-full">
            {({ open }) => (
                <>
                    <Menu.Button
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
                        <Menu.Items className="absolute left-0 mt-2 w-56 origin-top-left bg-neutral-800 border border-neutral-700 rounded shadow-lg z-10 focus:outline-none">
                            <div className="py-1">{items.map(item => <BillingDropdownItem key={item.to} {...item} />)}</div>
                        </Menu.Items>
                    </Transition>
                </>
            )}
        </Menu>
    );
};
