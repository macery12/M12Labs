import { useState, useEffect } from 'react';
import { Alert } from '@/elements/alert';
import { useStoreState } from '@/state/hooks';
import MessageBox, { FlashMessageType } from '@/elements/MessageBox';
import { usePersistedState } from '@/plugins/usePersistedState';
import { capitalize } from '@/lib/strings';
import { Dialog } from '@/elements/dialog';
import { ActiveAlert, getActiveAlerts } from '@/api/client/alerts';

interface ScopedAlertProps {
    scope: 'global' | 'dashboard' | 'server' | 'billing' | 'account' | 'admin';
}

export default ({ scope }: ScopedAlertProps) => {
    const { uuid: user } = useStoreState(s => s.user.data!);
    const [alerts, setAlerts] = useState<ActiveAlert[]>([]);
    const [dialogAlertIndex, setDialogAlertIndex] = useState(0);
    
    // Load active alerts for the specified scope
    useEffect(() => {
        getActiveAlerts(scope)
            .then(data => setAlerts(data))
            .catch(() => setAlerts([]));
    }, [scope]);

    // Filter alerts by position
    const topCenterAlerts = alerts.filter(a => a.position === 'top-center');
    const bottomLeftAlerts = alerts.filter(a => a.position === 'bottom-left');
    const bottomRightAlerts = alerts.filter(a => a.position === 'bottom-right');
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
            {/* Top Center Alerts */}
            {topCenterAlerts.map(alert => (
                <Alert 
                    key={alert.id} 
                    type={alert.type}
                >
                    {renderAlertContent(alert)}
                </Alert>
            ))}

            {/* Bottom Left Alerts */}
            {bottomLeftAlerts.length > 0 && (
                <div className={'fixed bottom-2 left-2 z-50 space-y-2'}>
                    {bottomLeftAlerts.map(alert => (
                        <MessageBox 
                            key={alert.id} 
                            type={alert.type as FlashMessageType}
                        >
                            {renderAlertContent(alert)}
                        </MessageBox>
                    ))}
                </div>
            )}

            {/* Bottom Right Alerts */}
            {bottomRightAlerts.length > 0 && (
                <div className={'fixed bottom-2 right-2 z-50 space-y-2'}>
                    {bottomRightAlerts.map(alert => (
                        <MessageBox 
                            key={alert.id} 
                            type={alert.type as FlashMessageType}
                        >
                            {renderAlertContent(alert)}
                        </MessageBox>
                    ))}
                </div>
            )}

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
