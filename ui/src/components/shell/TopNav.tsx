import { Link, useLocation } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { Menu, ChevronDown, LogOut, User as UserIcon, Shield } from 'lucide-react';
import { useSession } from '@/state/session';
import { useFlags } from '@/state/flags';
import { readCsrfToken } from '@/lib/globals';
import { cn } from '@/lib/cn';

function logout() {
    // Mirror V1: POST /auth/logout with CSRF, then bounce to the v2 login.
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/auth/logout';
    const csrf = document.createElement('input');
    csrf.type = 'hidden';
    csrf.name = '_token';
    csrf.value = readCsrfToken();
    form.appendChild(csrf);
    document.body.appendChild(form);
    form.submit();
}

export function TopNav({ onToggleSidebar }: { onToggleSidebar?: () => void }) {
    const { t } = useTranslation('nav');
    const user = useSession(s => s.user);
    const site = useFlags(s => s.site);
    const location = useLocation();
    const inAdmin = location.pathname.startsWith('/v2/admin');
    const isAdmin = !!user?.root_admin || !!user?.admin_role_id;

    return (
        <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-[var(--color-border)] bg-[var(--canvas)]/80 px-4 backdrop-blur">
            <div className="flex items-center gap-3">
                {onToggleSidebar && (
                    <button
                        onClick={onToggleSidebar}
                        className="flex h-10 w-10 items-center justify-center rounded-xl text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-2)] lg:hidden"
                        aria-label={t('topnav.toggleNav')}
                    >
                        <Menu className="h-5 w-5" />
                    </button>
                )}
                <Link to="/v2" className="flex items-center gap-2">
                    <div className="h-7 w-7 rounded-lg bg-[var(--brand)]" />
                    <span className="text-base font-semibold tracking-tight">{site?.name ?? 'M12Labs'}</span>
                </Link>
            </div>

            <div className="flex items-center gap-2">
                {isAdmin && (
                    <Link
                        to={inAdmin ? '/v2/account' : '/v2/admin'}
                        className={cn(
                            'hidden items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium sm:inline-flex',
                            'text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)]',
                        )}
                    >
                        <Shield className="h-4 w-4" />
                        {inAdmin ? t('topnav.exitAdmin') : t('topnav.admin')}
                    </Link>
                )}

                <DropdownMenu.Root>
                    <DropdownMenu.Trigger className="flex items-center gap-2 rounded-xl px-2 py-1.5 text-sm hover:bg-[var(--color-surface-2)]">
                        <img
                            src={user?.avatar_url}
                            alt=""
                            className="h-8 w-8 rounded-full bg-[var(--color-surface-2)] object-cover"
                        />
                        <span className="hidden font-medium sm:block">{user?.username ?? t('topnav.accountFallback')}</span>
                        <ChevronDown className="h-4 w-4 text-[var(--color-ink-faint)]" />
                    </DropdownMenu.Trigger>
                    <DropdownMenu.Portal>
                        <DropdownMenu.Content
                            align="end"
                            sideOffset={8}
                            className="z-50 min-w-48 rounded-xl border border-[var(--color-border-strong)] bg-[var(--color-surface)] p-1.5 shadow-xl"
                        >
                            <DropdownMenu.Item asChild>
                                <Link
                                    to="/v2/account"
                                    className="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm text-[var(--color-ink-muted)] outline-none hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)]"
                                >
                                    <UserIcon className="h-4 w-4" /> {t('topnav.account')}
                                </Link>
                            </DropdownMenu.Item>
                            <DropdownMenu.Separator className="my-1 h-px bg-[var(--color-border)]" />
                            <DropdownMenu.Item
                                onSelect={logout}
                                className="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm text-[var(--color-danger)] outline-none hover:bg-[var(--color-danger)]/10"
                            >
                                <LogOut className="h-4 w-4" /> {t('topnav.signOut')}
                            </DropdownMenu.Item>
                        </DropdownMenu.Content>
                    </DropdownMenu.Portal>
                </DropdownMenu.Root>
            </div>
        </header>
    );
}
