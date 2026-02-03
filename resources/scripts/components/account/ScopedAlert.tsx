import { useState, useEffect } from 'react';
import { Alert } from '@/elements/alert';
import { useStoreState } from '@/state/hooks';
import { usePersistedState } from '@/plugins/usePersistedState';
import { capitalize } from '@/lib/strings';
import { Dialog } from '@/elements/dialog';
import { ActiveAlert, getActiveAlerts } from '@/api/client/alerts';
import SlideOutAlert from '@/components/elements/SlideOutAlert';

interface ScopedAlertProps {
    scope: 'global' | 'dashboard' | 'server' | 'billing' | 'account' | 'admin';
    position?: 'top-center' | 'slide-out' | 'center' | 'all'; // Filter by position
}

export default ({ scope, position = 'all' }: ScopedAlertProps) => {
    const { uuid: user } = useStoreState(s => s.user.data!);
    const [alerts, setAlerts] = useState<ActiveAlert[]>([]);
    const [dialogAlertIndex, setDialogAlertIndex] = useState(0);

    // Load active alerts for the specified scope
    useEffect(() => {
        getActiveAlerts(scope)
            .then(data => {
                setAlerts(data);
            })
            .catch(() => setAlerts([]));
    }, [scope]);

    // Helper function to check if an alert is dismissed
    const isAlertDismissed = (alertId: number): boolean => {
        const dismissedKey = `alert_dismissed_${alertId}_${user}`;
        return localStorage.getItem(dismissedKey) === 'true';
    };

    // Helper function to dismiss an alert
    const dismissAlert = (alertId: number) => {
        const dismissedKey = `alert_dismissed_${alertId}_${user}`;
        localStorage.setItem(dismissedKey, 'true');
    };

    // Filter out dismissed alerts, notification-only alerts, and group by position
    const visibleAlerts = alerts.filter(a => !isAlertDismissed(a.id) && a.position !== 'notification');

    // Apply position filter if specified
    const filteredAlerts = position === 'all' ? visibleAlerts : visibleAlerts.filter(a => a.position === position);

    const topCenterAlerts =
        position === 'all' || position === 'top-center' ? filteredAlerts.filter(a => a.position === 'top-center') : [];
    const slideOutAlerts =
        position === 'all' || position === 'slide-out' ? filteredAlerts.filter(a => a.position === 'slide-out') : [];
    const centerAlerts =
        position === 'all' || position === 'center' ? filteredAlerts.filter(a => a.position === 'center') : [];

    // Get the current center alert to show
    const currentCenterAlert = centerAlerts[dialogAlertIndex] || null;

    // Always allow closing popups - this prevents page blocking
    const handleCenterAlertClose = () => {
        // Mark as dismissed for this user
        if (currentCenterAlert) {
            dismissAlert(currentCenterAlert.id);
        }

        // Move to next center alert if available
        if (dialogAlertIndex + 1 < centerAlerts.length) {
            setDialogAlertIndex(dialogAlertIndex + 1);
        } else {
            // Force re-render to hide the dialog
            setAlerts(prev => [...prev]);
        }
    };

    const handleSlideOutClose = (alertId: number) => {
        dismissAlert(alertId);
        // Force re-render to update visible alerts
        setAlerts(prev => [...prev]);
    };

    const handleTopCenterClose = (alertId: number) => {
        dismissAlert(alertId);
        // Force re-render to update visible alerts
        setAlerts(prev => [...prev]);
    };

    const renderAlertContent = (alert: ActiveAlert) => (
        <>
            {alert.title && <strong className={'mb-2 block text-base font-semibold'}>{alert.title}</strong>}
            <span className={'block'}>{alert.content}</span>
            {alert.link && (
                <a
                    href={alert.link}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={'mt-2 block underline hover:text-blue-300'}
                >
                    {alert.link_text || 'Learn more'}
                </a>
            )}
        </>
    );

    return (
        <>
            {/* Top Center Alerts with better spacing */}
            {topCenterAlerts.length > 0 && (
                <div className="mb-4 space-y-3">
                    {topCenterAlerts.map(alert => (
                        <Alert key={alert.id} type={alert.type} onClose={() => handleTopCenterClose(alert.id)}>
                            {renderAlertContent(alert)}
                        </Alert>
                    ))}
                </div>
            )}

            {/* Slide-out Alerts - positioned to avoid header conflicts */}
            {slideOutAlerts.map((alert, index) => (
                <SlideOutAlert
                    key={alert.id}
                    id={alert.id}
                    title={alert.title}
                    content={alert.content}
                    type={alert.type}
                    link={alert.link}
                    link_text={alert.link_text}
                    onClose={() => handleSlideOutClose(alert.id)}
                    index={index}
                />
            ))}

            {/* Center Dialog Alerts - Always allow dismissal to prevent page blocking */}
            {currentCenterAlert && (
                <Dialog.Confirm
                    open
                    buttonType={currentCenterAlert.type}
                    onClose={handleCenterAlertClose}
                    title={currentCenterAlert.title || capitalize(currentCenterAlert.type)}
                    onConfirmed={handleCenterAlertClose}
                >
                    {renderAlertContent(currentCenterAlert)}
                </Dialog.Confirm>
            )}
        </>
    );
};
