import { m } from '@/i18n/messages';
import { useEffect, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as Dropdown from '@radix-ui/react-dropdown-menu';
import {
    ChevronLeft,
    ChevronRight,
    MailCheck,
    MailWarning,
    MailX,
    MoreVertical,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Search,
    ShieldCheck,
    Trash2,
    Users,
} from 'lucide-react';
import {
    getAdminUsers,
    deleteUser,
    suspendUser,
    unsuspendUser,
    verifyUserEmail,
    type AdminUserQuery,
    type AdminUserRow,
} from '@/api/adminUsers';
import { getAdminRoles } from '@/api/adminRoles';
import { timeAgo } from '@/lib/format';
import { can } from '@/lib/can';
import { useAdminHeld } from '@/layouts/heldPermissions';
import { useFlashes } from '@/state/flashes';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { NoMatches } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import UserFormModal from './UserFormModal';

function EmailCell({ user }: { user: AdminUserRow }) {
    return (
        <span className="flex min-w-0 items-center gap-1.5">
            <span className="truncate">{user.email}</span>
            {!user.emailVerified && (
                <MailWarning
                    className="h-3.5 w-3.5 shrink-0 text-[var(--color-warning)]"
                    role="img"
                    aria-label={m['admin.users.unverifiedEmail']()}
                >
                    <title>{m['admin.users.unverifiedEmail']()}</title>
                </MailWarning>
            )}
        </span>
    );
}

function RowActions({
    user,
    canUpdate,
    canDelete,
    onEdit,
    onDelete,
}: {
    user: AdminUserRow;
    canUpdate: boolean;
    canDelete: boolean;
    onEdit: () => void;
    onDelete: () => void;
}) {
    const push = useFlashes(s => s.push);
    const qc = useQueryClient();

    if (!canUpdate && !canDelete) return null;

    const act = (fn: () => Promise<void>, msg: string) =>
        fn()
            .then(() => {
                push({ type: 'success', message: msg });
                return qc.invalidateQueries({ queryKey: ['admin', 'users'] });
            })
            .catch(() => push({ type: 'error', message: m['common.states.genericError']() }));

    return (
        <Dropdown.Root>
            <Dropdown.Trigger
                aria-label={m['admin.users.actionsLabel']()}
                className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--color-ink-faint)] transition-colors hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)] focus:outline-none"
            >
                <MoreVertical className="h-4 w-4" />
            </Dropdown.Trigger>
            <Dropdown.Portal>
                <Dropdown.Content
                    align="end"
                    sideOffset={4}
                    className="z-[60] w-48 overflow-hidden rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface)] p-1 shadow-xl shadow-black/30"
                >
                    {canUpdate && (
                        <>
                            <Item icon={Pencil} label={m['common.actions.edit']()} onSelect={onEdit} />
                            {!user.accessProfile?.isOwner &&
                                (user.suspended ? (
                                    <Item
                                        icon={Power}
                                        label={m['ui.actions.unsuspend']()}
                                        onSelect={() => act(() => unsuspendUser(user.id), m['admin.users.unsuspended']())}
                                    />
                                ) : (
                                    <Item
                                        icon={PowerOff}
                                        label={m['ui.actions.suspend']()}
                                        onSelect={() => act(() => suspendUser(user.id), m['admin.users.suspended']())}
                                    />
                                ))}
                            {user.emailVerified ? (
                                <Item
                                    icon={MailX}
                                    label={m['admin.users.unverifyEmail']()}
                                    onSelect={() => act(() => verifyUserEmail(user.id, false), m['admin.users.emailUnverified']())}
                                />
                            ) : (
                                <Item
                                    icon={MailCheck}
                                    label={m['admin.users.verifyEmail']()}
                                    onSelect={() => act(() => verifyUserEmail(user.id, true), m['admin.users.emailVerified']())}
                                />
                            )}
                        </>
                    )}
                    {canDelete && <Item icon={Trash2} label={m['common.actions.delete']()} danger onSelect={onDelete} />}
                </Dropdown.Content>
            </Dropdown.Portal>
        </Dropdown.Root>
    );
}

function Item({
    icon: Icon,
    label,
    onSelect,
    danger,
}: {
    icon: typeof Pencil;
    label: string;
    onSelect: () => void;
    danger?: boolean;
}) {
    return (
        <Dropdown.Item
            onSelect={onSelect}
            className={`flex cursor-pointer select-none items-center gap-2 rounded-lg px-3 py-2 text-sm outline-none data-[highlighted]:bg-[var(--color-surface-2)] ${
                danger ? 'text-[var(--color-danger)]' : 'text-[var(--color-ink)]'
            }`}
        >
            <Icon className="h-3.5 w-3.5" /> {label}
        </Dropdown.Item>
    );
}

// Account state only. Email verification used to share this pill, so an
// unverified but otherwise normal account read as a different "status" from
// a suspended one; it's an icon beside the email now.
function StatusPill({ user }: { user: AdminUserRow }) {
    const [label, cls] = user.suspended
        ? [m['common.states.suspended'](), 'bg-[var(--color-danger)]/15 text-[var(--color-danger)]']
        : [m['ui.states.active'](), 'bg-[var(--color-accent)]/15 text-[var(--color-accent)]'];
    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${cls}`}>{label}</span>
    );
}

export default function UsersListPage() {
    const held = useAdminHeld();
    const canCreate = can(held, 'users.create');
    const canUpdate = can(held, 'users.update');
    const canDelete = can(held, 'users.delete');

    const push = useFlashes(s => s.push);
    const qc = useQueryClient();

    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [profile, setProfile] = useState('');
    const [status, setStatus] = useState<'' | NonNullable<AdminUserQuery['status']>>('');
    const filtered = Boolean(search || profile || status);
    const [formOpen, setFormOpen] = useState(false);
    const [editUser, setEditUser] = useState<AdminUserRow | null>(null);
    const [toDelete, setToDelete] = useState<AdminUserRow | null>(null);

    // Debounce the search box, and reset to the first page on a new term.
    useEffect(() => {
        const t = setTimeout(() => {
            setSearch(searchInput.trim());
            setPage(1);
        }, 300);
        return () => clearTimeout(t);
    }, [searchInput]);

    const { data, isLoading, isError, isFetching } = useQuery({
        queryKey: ['admin', 'users', { page, search, profile, status }],
        queryFn: () =>
            getAdminUsers({
                page,
                search: search || undefined,
                accessProfile: profile || undefined,
                status: status || undefined,
                sort: '-root_admin',
            }),
        placeholderData: keepPreviousData,
    });

    // Profile names for the filter. Without roles.read the filter still offers
    // "no administrative access", which is the split that matters most.
    const rolesQ = useQuery({
        queryKey: ['admin', 'roles', 'all'],
        queryFn: () => getAdminRoles({ perPage: 100 }),
        enabled: can(held, 'roles.read'),
    });
    const profileOptions = [
        { value: '', label: m['admin.users.filter.anyProfile']() },
        { value: 'none', label: m['admin.access.users.noAccess']() },
        ...(rolesQ.data?.items ?? []).map(r => ({ value: String(r.id), label: r.name })),
    ];
    const statusOptions = [
        { value: '', label: m['ui.labels.allStatuses']() },
        { value: 'active', label: m['ui.states.active']() },
        { value: 'suspended', label: m['common.states.suspended']() },
        { value: 'unverified', label: m['admin.users.unverifiedEmail']() },
    ];
    const clearFilters = () => {
        setSearchInput('');
        setSearch('');
        setProfile('');
        setStatus('');
        setPage(1);
    };

    const items = data?.items ?? [];
    const pagination = data?.pagination;

    const del = useMutation({
        mutationFn: (id: number) => deleteUser(id),
        onSuccess: async () => {
            push({ type: 'success', message: m['admin.users.deleted']() });
            await qc.invalidateQueries({ queryKey: ['admin', 'users'] });
            setToDelete(null);
        },
        onError: () => push({ type: 'error', message: m['common.states.genericError']() }),
    });

    const openCreate = () => {
        setEditUser(null);
        setFormOpen(true);
    };
    const openEdit = (user: AdminUserRow) => {
        setEditUser(user);
        setFormOpen(true);
    };

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight text-[var(--color-ink)]">
                        {m['ui.labels.users']()}
                    </h1>
                    <p className="mt-1 text-sm text-[var(--color-ink-muted)]">
                        {m['admin.access.users.subtitle']()}
                    </p>
                </div>
                {canCreate && (
                    <Button onClick={openCreate}>
                        <Plus className="h-4 w-4" />
                        {m['ui.actions.createUser']()}
                    </Button>
                )}
            </div>

            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <div className="relative sm:w-80">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--color-ink-faint)]" />
                    <Input
                        value={searchInput}
                        onChange={e => setSearchInput(e.target.value)}
                        placeholder={m['admin.users.searchPlaceholder']()}
                        className="pl-9"
                    />
                </div>
                <div className="sm:w-56">
                    <Select
                        value={profile}
                        onChange={v => {
                            setProfile(v);
                            setPage(1);
                        }}
                        options={profileOptions}
                    />
                </div>
                <div className="sm:w-48">
                    <Select
                        value={status}
                        onChange={v => {
                            setStatus(v as typeof status);
                            setPage(1);
                        }}
                        options={statusOptions}
                    />
                </div>
            </div>

            <div className="overflow-hidden rounded-[var(--radius-card)] border border-[var(--color-border-strong)] bg-[var(--color-surface)]">
                {isLoading ? (
                    <div className="flex justify-center py-14">
                        <Spinner className="h-5 w-5" />
                    </div>
                ) : isError ? (
                    <p className="px-4 py-10 text-center text-sm text-[var(--color-danger)]">{m['admin.users.loadError']()}</p>
                ) : items.length === 0 && filtered ? (
                    <NoMatches onClear={clearFilters} />
                ) : items.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-4 py-14 text-center">
                        <Users className="h-8 w-8 text-[var(--color-ink-faint)]" />
                        <p className="text-sm text-[var(--color-ink-muted)]">{m['admin.users.empty']()}</p>
                    </div>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-[var(--color-border)] text-left text-xs uppercase tracking-wide text-[var(--color-ink-faint)]">
                                <th className="px-4 py-2.5 font-medium">{m['ui.labels.user']()}</th>
                                <th className="hidden px-4 py-2.5 font-medium md:table-cell">{m['admin.users.col.email']()}</th>
                                <th className="hidden px-4 py-2.5 font-medium lg:table-cell">
                                    {m['ui.labels.accessProfile']()}
                                </th>
                                <th className="px-4 py-2.5 font-medium">{m['ui.labels.status']()}</th>
                                <th className="hidden px-4 py-2.5 font-medium sm:table-cell">{m['ui.labels.created']()}</th>
                                <th className="w-8 px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {items.map(u => (
                                <tr
                                    key={u.id}
                                    className="border-b border-[var(--color-border)] transition-colors last:border-0 hover:bg-[var(--color-surface-2)]/40"
                                >
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-3">
                                            {u.avatarUrl ? (
                                                <img src={u.avatarUrl} alt="" className="h-8 w-8 shrink-0 rounded-full" />
                                            ) : (
                                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--color-surface-2)] text-xs font-semibold text-[var(--color-ink-muted)]">
                                                    {u.username.charAt(0).toUpperCase()}
                                                </span>
                                            )}
                                            <div className="min-w-0">
                                                <span className="flex items-center gap-1.5 font-medium text-[var(--color-ink)]">
                                                    <span className="truncate">{u.username}</span>
                                                    {u.accessProfile?.isOwner && (
                                                        <ShieldCheck
                                                            className="h-3.5 w-3.5 shrink-0 text-[var(--brand)]"
                                                            aria-label={m['admin.users.rootAdmin']()}
                                                        />
                                                    )}
                                                </span>
                                                <span className="block text-xs text-[var(--color-ink-faint)] md:hidden">
                                                    <EmailCell user={u} />
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="hidden px-4 py-3 text-[var(--color-ink-muted)] md:table-cell">
                                        <EmailCell user={u} />
                                    </td>
                                    <td className="hidden px-4 py-3 text-[var(--color-ink-muted)] lg:table-cell">
                                        {u.accessProfile?.isOwner ? (
                                            <span className="inline-flex items-center gap-1.5 rounded-full border border-[var(--brand)]/30 bg-[var(--brand-soft)] px-2 py-0.5 text-xs font-semibold text-[var(--brand)]">
                                                <span className="h-2 w-2 rounded-full bg-[var(--brand)]" />
                                                {m['admin.access.owner']()}
                                            </span>
                                        ) : u.adminRoleId ? (
                                            <span className="inline-flex items-center gap-1.5">
                                                <span
                                                    className="h-2.5 w-2.5 rounded-full"
                                                    style={{ backgroundColor: u.accessProfile?.color ?? 'var(--color-ink-faint)' }}
                                                />
                                                {u.roleName}
                                            </span>
                                        ) : (
                                            m['admin.access.users.noAccess']()
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusPill user={u} />
                                    </td>
                                    <td className="hidden px-4 py-3 text-xs text-[var(--color-ink-faint)] sm:table-cell">
                                        {timeAgo(u.createdAt)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <RowActions
                                            user={u}
                                            canUpdate={canUpdate}
                                            canDelete={canDelete}
                                            onEdit={() => openEdit(u)}
                                            onDelete={() => setToDelete(u)}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {pagination && pagination.totalPages > 1 && (
                <div className="flex items-center justify-between">
                    <p className="text-xs text-[var(--color-ink-faint)]">
                        {m['ui.labels.pageOfTotal']({ current: pagination.currentPage, total: pagination.totalPages })}
                        {isFetching && <Spinner className="ml-2 inline h-3 w-3" />}
                    </p>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pagination.currentPage <= 1 || isFetching}
                            onClick={() => setPage(p => Math.max(1, p - 1))}
                        >
                            <ChevronLeft className="h-4 w-4" />
                            {m['activity.prev']()}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={pagination.currentPage >= pagination.totalPages || isFetching}
                            onClick={() => setPage(p => p + 1)}
                        >
                            {m['ui.actions.next']()}
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}

            <UserFormModal open={formOpen} onClose={() => setFormOpen(false)} user={editUser} />

            <ConfirmDialog
                open={!!toDelete}
                onClose={() => setToDelete(null)}
                title={m['admin.users.deleteTitle']()}
                body={m['admin.users.deleteBody']({ name: toDelete?.username ?? '' })}
                confirmLabel={m['common.actions.delete']()}
                cancelLabel={m['common.actions.cancel']()}
                busy={del.isPending}
                onConfirm={() => toDelete && del.mutate(toDelete.id)}
            />
        </div>
    );
}
