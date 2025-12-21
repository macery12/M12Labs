import { useState, useEffect } from 'react';
import { Alert } from '@/elements/alert';
import { useStoreState } from '@/state/hooks';
import { usePersistedState } from '@/plugins/usePersistedState';
import { capitalize } from '@/lib/strings';
import { Dialog } from '@/elements/dialog';
import { ActiveAlert, getActiveAlerts } from '@/api/client/alerts';
import SlideOutAlert from '@/components/elements/SlideOutAlert';
import TopRightBanner from '@/components/elements/TopRightBanner';

interface ScopedAlertProps {
    scope: 'global' | 'dashboard' | 'server' | 'billing' | 'account' | 'admin';
}

export default ({ scope }: ScopedAlertProps) => {
    const { uuid: user } = useStoreState(s => s.user.data!);
    const [alerts, setAlerts] = useState<ActiveAlert[]>([]);
    const [dialogAlertIndex, setDialogAlertIndex] = useState(0);
    const [slideOutAlerts, setSlideOutAlerts] = useState<ActiveAlert[]>([]);
    const [topRightBanners, setTopRightBanners] = useState<ActiveAlert[]>([]);
    
    // Load active alerts for the specified scope
    useEffect(() => {
        getActiveAlerts(scope)
            .then(data => {
                setAlerts(data);
                // Initialize slide-out and banner alerts
                setSlideOutAlerts(data.filter(a => a.position === 'slide-out'));
                setTopRightBanners(data.filter(a => a.position === 'top-right-banner'));
            })
            .catch(() => setAlerts([]));
    }, [scope]);

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

    const handleBannerClose = (alertId: number) => {
        setTopRightBanners(prev => prev.filter(a => a.id !== alertId));
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

            {/* Top Right Banners */}
            {topRightBanners.map(alert => (
                <TopRightBanner
                    key={alert.id}
                    id={alert.id}
                    title={alert.title}
                    content={alert.content}
                    type={alert.type}
                    link={alert.link}
                    link_text={alert.link_text}
                    onClose={() => handleBannerClose(alert.id)}
                />
            ))}

            {/* Slide-out Alerts */}
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
