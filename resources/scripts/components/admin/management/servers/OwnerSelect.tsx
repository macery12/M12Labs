import { useFormikContext } from 'formik';
import { useEffect, useState } from 'react';

import { DEFAULT_USER_SEARCH_LIMIT, searchUsersPaginated } from '@/api/routes/admin/users';
import SearchableSelect, { Option } from '@/elements/SearchableSelect';
import type { User } from '@definitions/admin';

export default ({ selected }: { selected?: User }) => {
    const { setFieldValue } = useFormikContext();

    const [user, setUser] = useState<User | null>(selected || null);
    const [users, setUsers] = useState<User[] | null>(null);

    const loadUsers = async (query?: string) => {
        const { items } = await searchUsersPaginated({
            query: query || undefined,
            limit: DEFAULT_USER_SEARCH_LIMIT,
        });

        setUsers(items);
    };

    const onSearch = async (query: string) => {
        await loadUsers(query);
    };

    const onSelect = (user: User | null) => {
        setUser(user);
        setFieldValue('ownerId', user?.id || null);
    };

    const getSelectedText = (user: User | null): string => user?.email || '';

    useEffect(() => {
        loadUsers().catch(() => setUsers([]));
    }, []);

    return (
        <SearchableSelect
            id={'ownerId'}
            name={'ownerId'}
            label={'Owner'}
            placeholder={'Select a user...'}
            items={users}
            selected={user}
            setSelected={setUser}
            setItems={setUsers}
            onSearch={onSearch}
            onSelect={onSelect}
            getSelectedText={getSelectedText}
            nullable
        >
            {users?.map(d => (
                <Option key={d.id} selectId={'ownerId'} id={d.id} item={d} active={d.id === user?.id}>
                    {d.email} ({d.username})
                </Option>
            ))}
        </SearchableSelect>
    );
};
