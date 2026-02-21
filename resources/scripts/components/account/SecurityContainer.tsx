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
                    <div css={tw`overflow-x-auto`}>
                        <table css={tw`min-w-full text-sm`}>
                            <thead>
                                <tr css={tw`text-left text-gray-400 uppercase tracking-wider`}>
                                    <th css={tw`py-2`}>Device</th>
                                    <th css={tw`py-2`}>IP</th>
                                    <th css={tw`py-2`}>Location</th>
                                    <th css={tw`py-2`}>First seen</th>
                                    <th css={tw`py-2`}>Last active</th>
                                    <th css={tw`py-2`}>Status</th>
                                    <th css={tw`py-2 text-right`}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.map(session => {
                                    const status = statusForSession(session);

                                    return (
                                        <tr key={session.id} css={tw`border-t border-gray-700`}>
                                            <td css={tw`py-3`}>
                                                <div css={tw`font-medium text-white`}>{session.deviceName}</div>
                                                <div css={tw`text-xs text-gray-400 truncate max-w-xs`}>{session.userAgent}</div>
                                            </td>
                                            <td css={tw`py-3 text-gray-200`}>{session.ipAddress || 'Unknown'}</td>
                                            <td css={tw`py-3 text-gray-200`}>{session.location || 'Unknown'}</td>
                                            <td css={tw`py-3 text-gray-200`}>{formatDate(session.createdAt)}</td>
                                            <td css={tw`py-3 text-gray-200`}>{formatDate(session.lastActivityAt)}</td>
                                            <td css={tw`py-3`}>
                                                <Pill type={status.type}>{status.label}</Pill>
                                            </td>
                                            <td css={tw`py-3 text-right`}>
                                                <Button.Text
                                                    size={Button.Sizes.Small}
                                                    className={
                                                        session.revokedAt || session.isCurrent
                                                            ? undefined
                                                            : 'text-red-400 hover:text-red-300'
                                                    }
                                                    onClick={() => void handleRevoke(session.id)}
                                                    disabled={session.isCurrent || !!session.revokedAt}
                                                >
                                                    {session.revokedAt ? 'Revoked' : session.isCurrent ? 'Current' : 'Log out'}
                                                </Button.Text>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </ContentBox>
        </PageContentBlock>
    );
};
