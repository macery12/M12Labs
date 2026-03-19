import { useFormikContext } from 'formik';
import { useEffect, useState } from 'react';

import type { User } from '@definitions/admin';
import UserPicker from '@/components/admin/users/UserPicker';

export default ({ selected, isAdmin }: { selected?: User; isAdmin?: boolean }) => {
    const { setFieldValue } = useFormikContext();
    const [user, setUser] = useState<User | null>(selected || null);

    useEffect(() => {
        setUser(selected || null);
    }, [selected]);

    return (
        <UserPicker
            name={isAdmin ? 'assigned_to' : 'user_id'}
            label={isAdmin ? 'Assign to Administrator (optional)' : 'Ticket Owner'}
            value={user}
            onSelect={picked => {
                setUser(picked);
                setFieldValue(isAdmin ? 'assigned_to' : 'user_id', picked?.id || null);
            }}
            placeholder={'Search users by username, email, id...'}
        />
    );
};
