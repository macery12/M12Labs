import { useEffect, useState } from 'react';
import { Dialog } from '@/elements/dialog';
import { ActiveAlert, getActiveAlerts } from '@/api/client/alerts';
import { capitalize } from '@/lib/strings';
import tw from 'twin.macro';
import Spinner from '@/elements/Spinner';
import { format } from 'date-fns';
import { useStoreState } from '@/state/hooks';

interface Props {
    open: boolean;
    onClose: () => void;
}

export default ({ open, onClose }: Props) => {
    const { uuid: user } = useStoreState(s => s.user.data!);
    const [alerts, setAlerts] = useState<ActiveAlert[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        if (open) {
            setLoading(true);
            // Fetch all alerts across all scopes
            Promise.all([
                getActiveAlerts('global'),
                getActiveAlerts('dashboard'),
                getActiveAlerts('server'),
                getActiveAlerts('billing'),
                getActiveAlerts('account'),
                getActiveAlerts('admin'),
            ])
                .then(results => {
                    // Flatten and deduplicate by ID
                    const allAlerts = results.flat();
                    const uniqueAlerts = Array.from(new Map(allAlerts.map(alert => [alert.id, alert])).values());
                    // Sort by priority (highest first) then by ID (newest first)
                    uniqueAlerts.sort((a, b) => {
                        if (b.priority !== a.priority) {
                            return b.priority - a.priority;
                        }
                        return b.id - a.id;
                    });
                    setAlerts(uniqueAlerts);
                })
                .catch(error => {
                    console.error('Failed to load alert history:', error);
                    setAlerts([]);
                })
                .finally(() => setLoading(false));
        }
    }, [open]);

    // Check if an alert is dismissed
    const isAlertDismissed = (alertId: number): boolean => {
        const dismissedKey = `alert_dismissed_${alertId}_${user}`;
        return localStorage.getItem(dismissedKey) === 'true';
    };

    // Reopen a dismissed popup alert
    const reopenAlert = (alertId: number) => {
        const dismissedKey = `alert_dismissed_${alertId}_${user}`;
        localStorage.removeItem(dismissedKey);
        // Refresh the page to show the alert
        window.location.reload();
    };

    const getTypeColor = (type: string) => {
        switch (type) {
            case 'success':
                return 'text-green-400';
            case 'warning':
                return 'text-yellow-400';
            case 'danger':
                return 'text-red-400';
            default:
                return 'text-blue-400';
        }
    };

    const getTypeBadge = (type: string) => {
        switch (type) {
            case 'success':
                return 'bg-green-500/30 text-green-300 border-green-400';
            case 'warning':
                return 'bg-yellow-500/30 text-yellow-300 border-yellow-400';
            case 'danger':
                return 'bg-red-500/30 text-red-300 border-red-400';
            default:
                return 'bg-blue-500/30 text-blue-300 border-blue-400';
        }
    };

    const getTypeBorderColor = (type: string) => {
        switch (type) {
            case 'success':
                return 'border-l-green-500';
            case 'warning':
                return 'border-l-yellow-500';
            case 'danger':
                return 'border-l-red-500';
            default:
                return 'border-l-blue-500';
        }
    };

    return (
        <Dialog open={open} onClose={onClose} title="Alert History">
            <div css={tw`space-y-4`}>
                {loading ? (
                    <div css={tw`flex justify-center py-8`}>
                        <Spinner size="large" />
                    </div>
                ) : alerts.length === 0 ? (
                    <div css={tw`text-center py-8 text-gray-400`}>
                        <p>No alerts found</p>
                        <p css={tw`text-sm mt-2`}>There are currently no active alerts in the system.</p>
                    </div>
                ) : (
                    <div css={tw`max-h-96 overflow-y-auto space-y-3`}>
                        {alerts.map(alert => {
                            const isDismissed = isAlertDismissed(alert.id);
                            const isPopup = alert.position === 'center';

                            return (
                                <div
                                    key={alert.id}
                                    className={`rounded-lg border border-l-8 border-gray-700 bg-gray-800 p-4 ${getTypeBorderColor(
                                        alert.type,
                                    )}`}
                                >
                                    <div css={tw`flex items-start justify-between mb-2`}>
                                        <div css={tw`flex items-center gap-2 flex-wrap`}>
                                            {alert.title && (
                                                <h4 css={tw`font-semibold text-gray-200`}>{alert.title}</h4>
                                            )}
                                            <span
                                                className={`rounded border px-2 py-0.5 text-xs font-medium ${getTypeBadge(
                                                    alert.type,
                                                )}`}
                                            >
                                                {capitalize(alert.type)}
                                            </span>
                                            {isDismissed && (
                                                <span
                                                    css={tw`px-2 py-0.5 text-xs font-medium rounded border bg-gray-600/20 text-gray-400 border-gray-600`}
                                                >
                                                    Dismissed
                                                </span>
                                            )}
                                        </div>
                                        <span css={tw`text-xs text-gray-500 uppercase`}>{alert.scope}</span>
                                    </div>
                                    <p css={tw`text-gray-300 text-sm mb-2`}>{alert.content}</p>
                                    {alert.link && (
                                        <a
                                            href={alert.link}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            css={tw`text-blue-400 hover:text-blue-300 text-sm underline`}
                                        >
                                            {alert.link_text || 'Learn more'}
                                        </a>
                                    )}
                                    <div css={tw`flex items-center justify-between mt-3 pt-2 border-t border-gray-700`}>
                                        <div css={tw`text-xs text-gray-500`}>
                                            Position: <span css={tw`text-gray-400`}>{alert.position}</span>
                                        </div>
                                        {alert.start_at && (
                                            <div css={tw`text-xs text-gray-500`}>
                                                Active from:{' '}
                                                <span css={tw`text-gray-400`}>
                                                    {format(new Date(alert.start_at), 'MMM d, yyyy')}
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                    {/* Reopen button for dismissed alerts */}
                                    {isDismissed && (
                                        <div css={tw`mt-3 pt-2 border-t border-gray-700`}>
                                            <button
                                                type="button"
                                                onClick={() => reopenAlert(alert.id)}
                                                css={tw`px-3 py-1.5 text-sm bg-blue-600 hover:bg-blue-500 text-white rounded transition-colors flex items-center gap-2`}
                                            >
                                                <svg
                                                    css={tw`w-4 h-4`}
                                                    fill="none"
                                                    stroke="currentColor"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                        strokeWidth={2}
                                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                                    />
                                                </svg>
                                                {isPopup ? 'Reopen Popup' : 'Mark as Unread'}
                                            </button>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
            <Dialog.Footer>
                <button
                    type="button"
                    onClick={onClose}
                    css={tw`px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded transition-colors`}
                >
                    Close
                </button>
            </Dialog.Footer>
        </Dialog>
    );
};
