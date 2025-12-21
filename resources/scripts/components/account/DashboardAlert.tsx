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
    const [slideOutAlerts, setSlideOutAlerts] = useState<ActiveAlert[]>([]);
    
    // Load active alerts from the new system for dashboard scope
    useEffect(() => {
        getActiveAlerts('dashboard')
            .then(data => {
                setAlerts(data);
                // Initialize slide-out alerts
                setSlideOutAlerts(data.filter(a => a.position === 'slide-out'));
            })
            .catch(() => setAlerts([]));
    }, []);

    // Legacy alert support
    const [open, setOpen] = usePersistedState(`alert_${legacyAlert.uuid}_${user}`, true);

    // Filter alerts by position
    const topCenterAlerts = alerts.filter(a => a.position === 'top-center');
    const centerAlerts = alerts.filter(a => a.position === 'center');

    // Get the current center alert to show
    const currentCenterAlert = centerAlerts[dialogAlertIndex] || null;
    const [centerDialogDismissed, setCenterDialogDismissed] = usePersistedState(
        currentCenterAlert ? `alert_dismissed_${currentCenterAlert.id}_${user}` : 'none',
        false
    );

    // Always allow closing popups - this prevents page blocking
    const handleCenterAlertClose = () => {
        // Mark as dismissed for this user
        if (currentCenterAlert) {
            setCenterDialogDismissed(true);
        }
        
        // Move to next center alert if available
        if (dialogAlertIndex + 1 < centerAlerts.length) {
            setDialogAlertIndex(dialogAlertIndex + 1);
        }
    };

    const handleSlideOutClose = (alertId: number) => {
        setSlideOutAlerts(prev => prev.filter(a => a.id !== alertId));
    };

    const renderAlertContent = (alert: ActiveAlert) => (
        <>
            {alert.title && <strong className={'block mb-1'}>{alert.title}</strong>}
            {alert.content}
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
            {currentCenterAlert && !centerDialogDismissed && (
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
