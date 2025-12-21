import { useEffect, useState } from 'react';
import { Dialog } from '@/elements/dialog';
import { ActiveAlert, getActiveAlerts } from '@/api/client/alerts';
import { capitalize } from '@/lib/strings';
import tw from 'twin.macro';
import Spinner from '@/elements/Spinner';
import { format } from 'date-fns';

interface Props {
    open: boolean;
    onClose: () => void;
}

export default ({ open, onClose }: Props) => {
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
                    const uniqueAlerts = Array.from(
                        new Map(allAlerts.map(alert => [alert.id, alert])).values()
                    );
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

    const getTypeColor = (type: string) => {
        switch (type) {
            case 'success':
                return 'text-green-500';
            case 'warning':
                return 'text-yellow-500';
            case 'danger':
                return 'text-red-500';
            default:
                return 'text-blue-500';
        }
    };

    const getTypeBadge = (type: string) => {
        switch (type) {
            case 'success':
                return 'bg-green-500/20 text-green-400 border-green-500';
            case 'warning':
                return 'bg-yellow-500/20 text-yellow-400 border-yellow-500';
            case 'danger':
                return 'bg-red-500/20 text-red-400 border-red-500';
            default:
                return 'bg-blue-500/20 text-blue-400 border-blue-500';
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
                        {alerts.map(alert => (
                            <div
                                key={alert.id}
                                css={tw`p-4 rounded-lg bg-gray-800 border border-gray-700 hover:border-gray-600 transition-colors`}
                            >
                                <div css={tw`flex items-start justify-between mb-2`}>
                                    <div css={tw`flex items-center gap-2`}>
                                        {alert.title && (
                                            <h4 css={tw`font-semibold text-gray-200`}>{alert.title}</h4>
                                        )}
                                        <span
                                            className={`px-2 py-0.5 text-xs font-medium rounded border ${getTypeBadge(alert.type)}`}
                                        >
                                            {capitalize(alert.type)}
                                        </span>
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
                            </div>
                        ))}
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
