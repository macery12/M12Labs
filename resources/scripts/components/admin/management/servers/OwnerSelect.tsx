import { useFormikContext } from 'formik';
import { useEffect, useState } from 'react';

import type { User } from '@definitions/admin';
import UserPicker from '@/components/admin/users/UserPicker';

export default ({ selected }: { selected?: User }) => {
    const { setFieldValue } = useFormikContext();
    const [user, setUser] = useState<User | null>(selected || null);

    useEffect(() => {
        setUser(selected || null);
    }, [selected]);

    return (
        <UserPicker
            name={'ownerId'}
            label={'Owner'}
            value={user}
            onSelect={picked => {
                setUser(picked);
                setFieldValue('ownerId', picked?.id || null);
            }}
            placeholder={'Start typing to filter users...'}
        />
    );
};
