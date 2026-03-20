import { useCallback, useEffect, useMemo, useState } from 'react';
import AsyncSelect from 'react-select/async';
import { components as selectComponents } from 'react-select';
import tw, { theme } from 'twin.macro';
import { CSSObject } from '@emotion/serialize';
import { debounce } from 'debounce';

import { DEFAULT_USER_SEARCH_LIMIT, UserSearchOptions, searchUsersPaginated } from '@/api/routes/admin/users';
import type { User } from '@definitions/admin';
import Label from '@/elements/Label';
import Avatar from '@/elements/Avatar';
import { SelectStyle } from '@/elements/SelectField';
import { useStoreState } from '@/state/hooks';

interface UserPickerProps {
    name: string;
    label: string;
    value: User | null;
    onSelect: (user: User | null) => void;
    placeholder?: string;
    isClearable?: boolean;
    disabled?: boolean;
    className?: string;
    autoFocus?: boolean;
    menuPortalTarget?: HTMLElement;
}

interface UserOption {
    value: number;
    label: string;
    user: User;
}

const MAX_RESULTS = DEFAULT_USER_SEARCH_LIMIT;

const optionStyles: Partial<typeof SelectStyle> = {
    menu: (base: CSSObject) => ({
        ...base,
        maxHeight: 320,
        overflowY: 'auto',
        background: theme`colors.neutral.900`,
        border: `1px solid ${theme`colors.neutral.700`}`,
    }),
    menuList: (base: CSSObject) => ({
        ...base,
        background: theme`colors.neutral.900`,
    }),
};

const mergeStyles = (): typeof SelectStyle => {
    return {
        ...SelectStyle,
        ...optionStyles,
    };
};

const toOption = (user: User): UserOption => ({
    value: user.id,
    label: user.username || user.email,
    user,
});

const getUserIdentity = (user: User) => {
    const fallbackId = user.id ?? 'unknown';
    const primary = user.username || user.email || `User #${fallbackId}`;
    const secondary = user.email && user.email !== primary ? user.email : undefined;
    const avatar = user.uuid || user.email || user.username || String(fallbackId);

    return { primary, secondary, avatar };
};

const UserPicker = ({
    name,
    label,
    value,
    onSelect,
    placeholder = 'Select a user...',
    isClearable = true,
    disabled,
    className,
    autoFocus,
    menuPortalTarget,
}: UserPickerProps) => {
    const [defaultOptions, setDefaultOptions] = useState<UserOption[]>([]);
    const [initialTotal, setInitialTotal] = useState<number | null>(null);
    const { colors } = useStoreState(state => state.theme.data!);

    const loadUsers = useCallback(
        async (options: UserSearchOptions = {}, trackTotal = false): Promise<UserOption[]> => {
            const { items, pagination } = await searchUsersPaginated({
                limit: MAX_RESULTS,
                ...options,
            });

            if (trackTotal) {
                setInitialTotal(pagination.total);
            }

            return items.map(toOption);
        },
        [],
    );

    useEffect(() => {
        let isMounted = true;
        loadUsers({}, true)
            .then(options => {
                if (isMounted) {
                    setDefaultOptions(options);
                }
            })
            .catch(() => {
                if (isMounted) {
                    setDefaultOptions([]);
                }
            });

        return () => {
            isMounted = false;
        };
    }, [loadUsers]);

    const debouncedLoader = useMemo(
        () =>
            debounce((inputValue: string, callback: (options: UserOption[]) => void) => {
                loadUsers({ query: inputValue || undefined })
                    .then(callback)
                    .catch(() => callback([]));
            }, 750),
        [loadUsers],
    );

    const selectStyles = useMemo(() => {
        const baseStyles = mergeStyles();
        return {
            ...baseStyles,
            control: (base, props) => ({
                ...baseStyles.control(base, props),
                background: colors.background,
                borderColor: props.isFocused ? theme`colors.primary.300` : theme`colors.neutral.500`,
                ':hover': {
                    ...(baseStyles.control(base, props)?.[':hover'] || {}),
                    borderColor: props.isFocused ? theme`colors.primary.300` : theme`colors.neutral.400`,
                },
            }),
            valueContainer: (base, props) => ({
                ...baseStyles.valueContainer(base, props),
                background: colors.background,
            }),
            input: (base, props) => ({
                ...baseStyles.input(base, props),
                background: colors.background,
            }),
            singleValue: (base, props) => ({
                ...baseStyles.singleValue?.(base, props),
                color: colors.headers ?? theme`colors.neutral.100`,
            }),
            placeholder: (base, props) => ({
                ...baseStyles.placeholder?.(base, props),
                color: theme`colors.neutral.400`,
            }),
        };
    }, [colors.background, colors.headers]);

    return (
        <div className={className}>
            <Label htmlFor={name}>{label}</Label>
            <AsyncSelect
                inputId={name}
                cacheOptions
                defaultOptions={defaultOptions}
                loadOptions={(inputValue, callback) => debouncedLoader(inputValue, callback)}
                value={value ? toOption(value) : null}
                onChange={option => onSelect(option ? (option as UserOption).user : null)}
                placeholder={placeholder}
                isClearable={isClearable}
                isDisabled={disabled}
                openMenuOnFocus
                openMenuOnClick
                menuPlacement="auto"
                menuPortalTarget={menuPortalTarget}
                styles={selectStyles}
                formatOptionLabel={(option, { context }) => {
                    const user = (option as UserOption).user;
                    const { primary, secondary, avatar } = getUserIdentity(user);
                    if (context === 'value') {
                        return (
                            <div css={tw`flex items-center gap-2`}>
                                <Avatar name={avatar} size={24} />
                                <span css={[tw`text-sm`, { color: colors.headers ?? theme`colors.neutral.100` }]}>{primary}</span>
                                {secondary && (
                                    <span css={[tw`text-xs`, { color: theme`colors.neutral.400` }]}>{secondary}</span>
                                )}
                            </div>
                        );
                    }

                    return (
                        <div css={tw`flex items-center gap-3`}>
                            <Avatar name={avatar} size={28} />
                            <div css={tw`flex flex-col`}>
                                <span css={tw`text-sm text-neutral-100`}>{primary}</span>
                                {secondary && <span css={tw`text-xs text-neutral-400`}>{secondary}</span>}
                            </div>
                            <div css={tw`ml-auto flex items-center gap-2`}>
                                <span
                                    css={[
                                        tw`text-[11px] rounded px-2 py-0.5 bg-neutral-700 text-neutral-200`,
                                        { lineHeight: '18px' },
                                    ]}
                                >
                                    ID: {user.id}
                                </span>
                                {user.externalId && (
                                    <span
                                        css={[
                                            tw`text-[11px] rounded px-2 py-0.5 bg-primary-500/20 text-primary-200`,
                                            { lineHeight: '18px' },
                                        ]}
                                    >
                                        {user.externalId}
                                    </span>
                                )}
                            </div>
                        </div>
                    );
                }}
                noOptionsMessage={({ inputValue }) =>
                    inputValue
                        ? 'No results found.'
                        : initialTotal !== null && initialTotal <= MAX_RESULTS
                          ? 'All users loaded.'
                          : 'Start typing to search users.'
                }
                loadingMessage={() => 'Loading users...'}
                components={{
                    DropdownIndicator: props => (
                        <selectComponents.DropdownIndicator {...props}>
                            <svg
                                css={{ width: 16, height: 16, color: theme`colors.neutral.300` }}
                                xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth="2"
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </selectComponents.DropdownIndicator>
                    ),
                }}
            />
        </div>
    );
};

export default UserPicker;
