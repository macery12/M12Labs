import type { ReactNode } from 'react';
import { useStoreState } from '@/state/hooks';
import Spinner from '@/elements/Spinner';
import AdminAccessDenied from '@/elements/AdminAccessDenied';
import { useAdminPermissions } from '@/plugins/useAdminPermissions';

interface Props {
    /** The permission string that must be present in the user's role (e.g. 'roles.read'). */
    permission: string;
    children: ReactNode;
}

/**
 * Wraps an admin page and blocks access if the current user's role does not
 * include the required permission. Root admins always pass through.
 */
export default function RequireAdminPermission({ permission, children }: Props) {
    const rootAdmin = useStoreState(state => state.user.data?.rootAdmin ?? false);
    const { can, isLoading } = useAdminPermissions();

    // Root admins bypass all permission checks.
    if (rootAdmin) return <>{children}</>;

    // Show spinner while fetching the permission list.
    if (isLoading) return <Spinner size={'large'} centered />;

    // Block access if the user lacks the required permission.
    if (!can(permission)) return <AdminAccessDenied permission={permission} />;

    return <>{children}</>;
}
