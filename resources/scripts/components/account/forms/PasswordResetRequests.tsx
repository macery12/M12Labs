import { useEffect, useState } from 'react';
import { Actions, useStoreActions } from 'easy-peasy';
import tw from 'twin.macro';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { getMyPasswordResetRequests } from '@/api/routes/account/password-reset-requests';
import type { PasswordResetRequest } from '@/api/routes/account/password-reset-requests';
import { format } from 'date-fns';

const getStatusColor = (status: string) => {
    switch (status) {
        case 'approved':
            return tw`text-green-400`;
        case 'denied':
            return tw`text-red-400`;
        default:
            return tw`text-yellow-400`;
    }
};

const getStatusLabel = (status: string) => {
    return status.charAt(0).toUpperCase() + status.slice(1);
};

export default () => {
    const [loading, setLoading] = useState(true);
    const [requests, setRequests] = useState<PasswordResetRequest[]>([]);

    const { clearFlashes, addFlash } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);

    useEffect(() => {
        clearFlashes('account:reset-requests');
        loadRequests();
    }, []);

    const loadRequests = () => {
        setLoading(true);
        getMyPasswordResetRequests()
            .then(setRequests)
            .catch(error => {
                console.error(error);
                addFlash({
                    type: 'error',
                    key: 'account:reset-requests',
                    title: 'Error',
                    message: httpErrorToHuman(error),
                });
            })
            .finally(() => setLoading(false));
    };

    if (loading) {
        return <SpinnerOverlay size="large" visible />;
    }

    if (requests.length === 0) {
        return (
            <p css={tw`text-sm text-neutral-400`}>
                You have not submitted any password reset requests. If you need help accessing your account, you can
                request admin assistance from the forgot password page.
            </p>
        );
    }

    return (
        <div css={tw`space-y-4`}>
            {requests.map(request => (
                <div key={request.id} css={tw`bg-neutral-700 p-4 rounded border border-neutral-600`}>
                    <div css={tw`flex items-center justify-between mb-2`}>
                        <span css={[tw`text-sm font-semibold`, getStatusColor(request.status)]}>
                            {getStatusLabel(request.status)}
                        </span>
                        <span css={tw`text-xs text-neutral-400`}>
                            {format(new Date(request.created_at), 'MMM dd, yyyy HH:mm')}
                        </span>
                    </div>
                    <div css={tw`text-sm space-y-1`}>
                        {request.discord_username && (
                            <p css={tw`text-neutral-300`}>
                                <span css={tw`text-neutral-400`}>Discord:</span> {request.discord_username}
                            </p>
                        )}
                        {request.contact_email && (
                            <p css={tw`text-neutral-300`}>
                                <span css={tw`text-neutral-400`}>Email:</span> {request.contact_email}
                            </p>
                        )}
                        <p css={tw`text-neutral-300`}>
                            <span css={tw`text-neutral-400`}>Reason:</span> {request.reason}
                        </p>
                        {request.admin_notes && (
                            <p css={tw`text-neutral-300 mt-2 pt-2 border-t border-neutral-600`}>
                                <span css={tw`text-neutral-400`}>Admin Notes:</span> {request.admin_notes}
                            </p>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
};
