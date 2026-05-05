import { useEffect, useMemo } from 'react';
import useSWR from 'swr';
import tw from 'twin.macro';
import ContentBox from '@/elements/ContentBox';
import PageContentBlock from '@/elements/PageContentBlock';
import FlashMessageRender from '@/elements/FlashMessageRender';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import Pill from '@/elements/Pill';
import { Button } from '@/elements/button';
import { useFlashKey } from '@/plugins/useFlash';
import { getAccountSessions, revokeAccountSession, revokeAllAccountSessions } from '@/api/routes/account';
import { AccountSession } from '@definitions/account';
import classNames from 'classnames';

const statusForSession = (session: AccountSession) => {
    if (session.revokedAt) return { label: 'Revoked', type: 'danger' as const };
    if (session.isCurrent) return { label: 'Current', type: 'success' as const };
    return { label: 'Active', type: 'info' as const };
};

const formatDate = (value: Date | null) => (value ? value.toLocaleString() : '—');

export default () => {
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('account:sessions');
    const { data, error, mutate, isValidating } = useSWR<AccountSession[]>(
        ['account', 'sessions'],
        () => getAccountSessions(),
        {
            revalidateOnFocus: false,
            revalidateOnMount: true,
        },
    );

    useEffect(() => {
        clearAndAddHttpError(error);
    }, [error]);

    const handleRevoke = async (id: number) => {
        clearFlashes();
        await revokeAccountSession(id);
        await mutate();
    };

    const handleRevokeAll = async () => {
        clearFlashes();
        if (!confirm('Log out all other devices? This will sign out every device except the one you are using now.')) {
            return;
        }

        await revokeAllAccountSessions(false);
        await mutate();
    };

    const hasActive = useMemo(() => data?.some(s => !s.revokedAt) ?? false, [data]);

    return (
        <PageContentBlock
            title="Account Security"
            header
            description={'Review active sessions, see device details, and sign out devices you do not recognize.'}
        >
            <FlashMessageRender byKey={'account:sessions'} />
            <ContentBox title={'Logged in devices'}>
                <div css={tw`flex justify-between items-center mb-4`}>
                    <p css={tw`text-sm text-gray-300`}>
                        {hasActive
                            ? 'These sessions are currently able to access your account.'
                            : 'No active sessions found.'}
                    </p>
                    <Button.Text size={Button.Sizes.Small} onClick={() => void handleRevokeAll()} disabled={!hasActive}>
                        Log out all devices
                    </Button.Text>
                </div>
                <SpinnerOverlay visible={!data && isValidating} />
                {!data ? (
                    <p css={tw`text-sm text-gray-300`}>Loading sessions...</p>
                ) : data.length === 0 ? (
                    <p css={tw`text-sm text-gray-300`}>No sessions found for this account.</p>
                ) : (
                    <>
                        <div css={tw`hidden md:block overflow-x-auto`}>
                            <table css={tw`min-w-full text-sm table-fixed`} className="logged-devices-table">
                                <colgroup>
                                    <col style={{ width: '44%' }} />
                                    <col style={{ width: '12%' }} />
                                    <col style={{ width: '10%' }} />
                                    <col style={{ width: '10%' }} />
                                    <col style={{ width: '10%' }} />
                                    <col style={{ width: '7%' }} />
                                    <col style={{ width: '7%' }} />
                                </colgroup>
                                <thead>
                                    <tr css={tw`text-left text-gray-400 tracking-wide`}>
                                        <th css={tw`py-3 px-4`}>Device</th>
                                        <th css={tw`py-3 px-4`}>IP</th>
                                        <th css={tw`py-3 px-4`}>Location</th>
                                        <th css={tw`py-3 px-4`}>First seen</th>
                                        <th css={tw`py-3 px-4`}>Last active</th>
                                        <th css={tw`py-3 px-4`}>Status</th>
                                        <th css={tw`py-3 px-4 text-right`}>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.map(session => {
                                        const status = statusForSession(session);

                                        return (
                                            <tr
                                                key={session.id}
                                                css={tw`border-t border-gray-800 hover:bg-white/5 transition-colors`}
                                            >
                                                <td css={tw`py-4 px-4 align-top`}>
                                                    <div css={tw`font-semibold text-white truncate max-w-full`}>
                                                        {session.deviceName}
                                                    </div>
                                                    <div
                                                        css={tw`text-xs text-gray-400 mt-1 leading-snug line-clamp-2 max-w-full break-words`}
                                                        title={session.userAgent || undefined}
                                                    >
                                                        {session.userAgent || 'Unknown user agent'}
                                                    </div>
                                                </td>
                                                <td css={tw`py-4 px-4 text-gray-200 align-top truncate`}>
                                                    {session.ipAddress || 'Unknown'}
                                                </td>
                                                <td css={tw`py-4 px-4 text-gray-200 align-top truncate`}>
                                                    {session.location || 'Unknown'}
                                                </td>
                                                <td css={tw`py-4 px-4 text-gray-200 align-top truncate`}>
                                                    {formatDate(session.createdAt)}
                                                </td>
                                                <td css={tw`py-4 px-4 text-gray-200 align-top truncate`}>
                                                    {formatDate(session.lastActivityAt)}
                                                </td>
                                                <td css={tw`py-4 px-4 align-top`}>
                                                    <Pill type={status.type}>{status.label}</Pill>
                                                </td>
                                                <td css={tw`py-4 px-4 text-right align-top`}>
                                                    <Button.Text
                                                        size={Button.Sizes.Small}
                                                        className={classNames(
                                                            session.revokedAt || session.isCurrent
                                                                ? undefined
                                                                : 'text-red-400 hover:text-red-300',
                                                        )}
                                                        onClick={() => void handleRevoke(session.id)}
                                                        disabled={session.isCurrent || !!session.revokedAt}
                                                    >
                                                        {session.revokedAt
                                                            ? 'Revoked'
                                                            : session.isCurrent
                                                            ? 'Current'
                                                            : 'Log out'}
                                                    </Button.Text>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        {/* Mobile layout */}
                        <div css={tw`space-y-4 md:hidden`}>
                            {data.map(session => {
                                const status = statusForSession(session);

                                return (
                                    <div
                                        key={session.id}
                                        css={tw`rounded-lg border border-gray-800 bg-black/40 p-4 space-y-3`}
                                    >
                                        <div>
                                            <div css={tw`font-semibold text-white`}>{session.deviceName}</div>
                                            <div
                                                css={tw`text-xs text-gray-400 mt-1 leading-snug line-clamp-2`}
                                                title={session.userAgent || undefined}
                                            >
                                                {session.userAgent || 'Unknown user agent'}
                                            </div>
                                        </div>
                                        <div css={tw`flex flex-wrap gap-3 text-sm text-gray-200`}>
                                            <span>
                                                <span css={tw`text-gray-400`}>IP:</span>{' '}
                                                {session.ipAddress || 'Unknown'}
                                            </span>
                                            <span>
                                                <span css={tw`text-gray-400`}>Location:</span>{' '}
                                                {session.location || 'Unknown'}
                                            </span>
                                        </div>
                                        <div css={tw`text-sm text-gray-200 flex flex-wrap gap-3`}>
                                            <span>
                                                <span css={tw`text-gray-400`}>First:</span>{' '}
                                                {formatDate(session.createdAt)}
                                            </span>
                                            <span>
                                                <span css={tw`text-gray-400`}>Last:</span>{' '}
                                                {formatDate(session.lastActivityAt)}
                                            </span>
                                        </div>
                                        <div css={tw`flex items-center justify-between`}>
                                            <Pill type={status.type}>{status.label}</Pill>
                                            <Button.Text
                                                size={Button.Sizes.Small}
                                                className={classNames(
                                                    session.revokedAt || session.isCurrent
                                                        ? undefined
                                                        : 'text-red-400 hover:text-red-300',
                                                )}
                                                onClick={() => void handleRevoke(session.id)}
                                                disabled={session.isCurrent || !!session.revokedAt}
                                            >
                                                {session.revokedAt
                                                    ? 'Revoked'
                                                    : session.isCurrent
                                                    ? 'Current'
                                                    : 'Log out'}
                                            </Button.Text>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}
            </ContentBox>
        </PageContentBlock>
    );
};
