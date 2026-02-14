import { useState, useEffect, useCallback } from 'react';
import tw from 'twin.macro';
import AdminContentBlock from '@/elements/AdminContentBlock';
import { Button } from '@/elements/button';
import {
    getAllPasswordResetRequests,
    approvePasswordResetRequest,
    denyPasswordResetRequest,
    type AdminPasswordResetRequest,
} from '@/api/routes/admin/password-reset-requests';
import { httpErrorToHuman } from '@/api/http';
import { useFlashKey } from '@/plugins/useFlash';
import SpinnerOverlay from '@/elements/SpinnerOverlay';
import { format } from 'date-fns';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheck, faTimes, faFilter } from '@fortawesome/free-solid-svg-icons';
import { Textarea } from '@/elements/Input';

const getStatusColor = (status: string) => {
    switch (status) {
        case 'approved':
            return tw`bg-green-500`;
        case 'denied':
            return tw`bg-red-500`;
        default:
            return tw`bg-yellow-500`;
    }
};

const getStatusLabel = (status: string) => {
    return status.charAt(0).toUpperCase() + status.slice(1);
};

export default function PasswordResetRequestsContainer() {
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState<number | null>(null);
    const [requests, setRequests] = useState<AdminPasswordResetRequest[]>([]);
    const [filter, setFilter] = useState<string>('');
    const [adminNotes, setAdminNotes] = useState<Record<number, string>>({});
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlashKey('admin:password-reset-requests');

    const loadRequests = useCallback(() => {
        clearFlashes();
        setLoading(true);
        getAllPasswordResetRequests(filter || undefined)
            .then(setRequests)
            .catch(clearAndAddHttpError)
            .finally(() => setLoading(false));
    }, [filter]);

    useEffect(() => {
        loadRequests();
    }, [filter]);

    const handleApprove = (id: number, adminNotes?: string) => {
        setSubmitting(id);
        clearFlashes();

        approvePasswordResetRequest(id, adminNotes)
            .then(() => {
                addFlash({
                    type: 'success',
                    message: 'Password reset request approved.',
                });
                loadRequests();
            })
            .catch(clearAndAddHttpError)
            .finally(() => setSubmitting(null));
    };

    const handleDeny = (id: number, adminNotes?: string) => {
        setSubmitting(id);
        clearFlashes();

        denyPasswordResetRequest(id, adminNotes)
            .then(() => {
                addFlash({
                    type: 'success',
                    message: 'Password reset request denied.',
                });
                loadRequests();
            })
            .catch(clearAndAddHttpError)
            .finally(() => setSubmitting(null));
    };

    return (
        <AdminContentBlock title={'Password Reset Requests'}>
            <div css={tw`w-full flex flex-row items-center mb-8`}>
                <div css={tw`flex flex-col flex-shrink`} style={{ minWidth: '0' }}>
                    <h2 css={tw`text-2xl text-neutral-50 font-header font-medium`}>Password Reset Requests</h2>
                    <p css={tw`text-base text-neutral-400`}>
                        Manage user password reset requests that require admin assistance.
                    </p>
                </div>
            </div>

            <div css={tw`mb-6 flex items-center gap-4`}>
                <FontAwesomeIcon icon={faFilter} css={tw`text-neutral-400`} />
                <select
                    value={filter}
                    onChange={e => setFilter(e.target.value)}
                    css={tw`block w-48 px-3 py-2 bg-neutral-700 border border-neutral-600 rounded text-sm text-neutral-100 focus:border-primary-400 focus:outline-none`}
                >
                    <option value="">All Requests</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="denied">Denied</option>
                </select>
            </div>

            <SpinnerOverlay visible={loading} size="large" />

            {!loading && requests.length === 0 ? (
                <div css={tw`bg-neutral-700 p-8 rounded text-center`}>
                    <p css={tw`text-neutral-400`}>
                        {filter
                            ? `No ${filter} password reset requests found.`
                            : 'No password reset requests found.'}
                    </p>
                </div>
            ) : (
                <div css={tw`space-y-4`}>
                    {requests.map(request => (
                        <div
                            key={request.id}
                            css={tw`bg-neutral-700 p-6 rounded border border-neutral-600 relative`}
                        >
                            {submitting === request.id && <SpinnerOverlay visible size="large" />}

                            <div css={tw`flex items-start justify-between mb-4`}>
                                <div>
                                    <div css={tw`flex items-center gap-3 mb-2`}>
                                        <span
                                            css={[
                                                tw`px-2 py-1 rounded text-xs font-semibold text-white`,
                                                getStatusColor(request.status),
                                            ]}
                                        >
                                            {getStatusLabel(request.status)}
                                        </span>
                                        <span css={tw`text-sm text-neutral-400`}>
                                            Request #{request.id}
                                        </span>
                                    </div>
                                    <h3 css={tw`text-lg font-semibold text-neutral-100`}>
                                        {request.user_username}
                                    </h3>
                                    <p css={tw`text-sm text-neutral-400`}>{request.user_email}</p>
                                </div>

                                <div css={tw`text-right`}>
                                    <p css={tw`text-xs text-neutral-400`}>Submitted</p>
                                    <p css={tw`text-sm text-neutral-300`}>
                                        {format(new Date(request.created_at), 'MMM dd, yyyy HH:mm')}
                                    </p>
                                </div>
                            </div>

                            <div css={tw`space-y-3 mb-4 text-sm`}>
                                {request.discord_username && (
                                    <div>
                                        <span css={tw`text-neutral-400`}>Discord:</span>{' '}
                                        <span css={tw`text-neutral-200`}>{request.discord_username}</span>
                                    </div>
                                )}
                                {request.contact_email && (
                                    <div>
                                        <span css={tw`text-neutral-400`}>Contact Email:</span>{' '}
                                        <span css={tw`text-neutral-200`}>{request.contact_email}</span>
                                    </div>
                                )}
                                <div>
                                    <span css={tw`text-neutral-400`}>Reason:</span>
                                    <p css={tw`text-neutral-200 mt-1 whitespace-pre-wrap`}>{request.reason}</p>
                                </div>
                            </div>

                            {request.admin_notes && (
                                <div css={tw`bg-neutral-800 p-3 rounded mb-4`}>
                                    <p css={tw`text-xs text-neutral-400 mb-1`}>Admin Notes</p>
                                    <p css={tw`text-sm text-neutral-200`}>{request.admin_notes}</p>
                                    {request.admin_username && (
                                        <p css={tw`text-xs text-neutral-400 mt-2`}>
                                            By {request.admin_username} on{' '}
                                            {format(new Date(request.updated_at), 'MMM dd, yyyy HH:mm')}
                                        </p>
                                    )}
                                </div>
                            )}

                            {request.status === 'pending' && (
                                <div>
                                    <div css={tw`mb-4`}>
                                        <label css={tw`block text-sm font-medium text-neutral-300 mb-2`}>
                                            Admin Notes (Optional)
                                        </label>
                                        <Textarea
                                            value={adminNotes[request.id] || ''}
                                            onChange={e =>
                                                setAdminNotes(prev => ({ ...prev, [request.id]: e.target.value }))
                                            }
                                            placeholder="Add any notes about your decision..."
                                            css={tw`w-full`}
                                            rows={3}
                                        />
                                        <p css={tw`text-xs text-neutral-400 mt-1`}>
                                            Add any notes about your decision.
                                        </p>
                                    </div>
                                    <div css={tw`flex gap-3`}>
                                        <Button.Success
                                            onClick={() => handleApprove(request.id, adminNotes[request.id])}
                                            disabled={submitting !== null}
                                        >
                                            <FontAwesomeIcon icon={faCheck} css={tw`mr-2`} />
                                            Approve Request
                                        </Button.Success>
                                        <Button.Danger
                                            onClick={() => handleDeny(request.id, adminNotes[request.id])}
                                            disabled={submitting !== null}
                                        >
                                            <FontAwesomeIcon icon={faTimes} css={tw`mr-2`} />
                                            Deny Request
                                        </Button.Danger>
                                    </div>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </AdminContentBlock>
    );
}
