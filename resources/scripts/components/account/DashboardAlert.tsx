import { useState, useEffect } from 'react';
import { Alert } from '@/elements/alert';
import { useStoreState } from '@/state/hooks';
import { usePersistedState } from '@/plugins/usePersistedState';
import { capitalize } from '@/lib/strings';
import { Dialog } from '@/elements/dialog';
import { ActiveAlert, getActiveAlerts } from '@/api/client/alerts';
import SlideOutAlert from '@/components/elements/SlideOutAlert';

export default () => {
    const { uuid: user } = useStoreState(s => s.user.data!);
    const { alert: legacyAlert } = useStoreState(s => s.everest.data!);
    const [alerts, setAlerts] = useState<ActiveAlert[]>([]);
    const [dialogAlertIndex, setDialogAlertIndex] = useState(0);
    
    // Load active alerts from the new system for dashboard scope
    useEffect(() => {
        getActiveAlerts('dashboard')
            .then(data => {
                setAlerts(data);
            })
            .catch(() => setAlerts([]));
    }, []);

    // Legacy alert support
    const [open, setOpen] = usePersistedState(`alert_${legacyAlert.uuid}_${user}`, true);

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

    // Filter out dismissed alerts and group by position
    const visibleAlerts = alerts.filter(a => !isAlertDismissed(a.id));
    const topCenterAlerts = visibleAlerts.filter(a => a.position === 'top-center');
    const slideOutAlerts = visibleAlerts.filter(a => a.position === 'slide-out');
    const centerAlerts = visibleAlerts.filter(a => a.position === 'center');

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
            {alert.title && (
                <strong className={'block mb-2 text-base font-semibold'}>
                    {alert.title}
                </strong>
            )}
            <span className={'block'}>{alert.content}</span>
            {alert.link && (
                <a 
                    href={alert.link} 
                    target="_blank" 
                    rel="noopener noreferrer"
                    className={'block mt-2 underline hover:text-blue-300'}
                >
                    {alert.link_text || 'Learn more'}
                </a>
            )}
        </>
    );

    return (
        <>
            {/* Legacy alert support */}
            {legacyAlert.enabled && legacyAlert.position === 'top-center' && (
                <Alert type={legacyAlert.type}>{legacyAlert.content}</Alert>
            )}
            {legacyAlert.enabled && legacyAlert.position === 'center' && open && (
                <Dialog.Confirm
                    open
                    buttonType={legacyAlert.type}
                    onClose={() => setOpen(false)}
                    title={capitalize(legacyAlert.type)}
                    onConfirmed={() => setOpen(false)}
                >
                    {legacyAlert.content}
                </Dialog.Confirm>
            )}

            {/* New multi-alert system - Top Center */}
            {topCenterAlerts.map(alert => (
                <Alert 
                    key={alert.id} 
                    type={alert.type}
                    onClose={() => handleTopCenterClose(alert.id)}
                >
                    {renderAlertContent(alert)}
                </Alert>
            ))}

            {/* Slide-out Alerts (replaces bottom-left and bottom-right) */}
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
