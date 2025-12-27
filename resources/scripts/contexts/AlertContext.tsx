import React, { createContext, useContext, useState, useCallback, useEffect } from 'react';
import { nanoid } from 'nanoid';

export type AlertType = 'success' | 'error' | 'info' | 'warning';

export interface AlertAction {
    label: string;
    onClick: () => void;
    variant?: 'primary' | 'secondary';
}

export interface Alert {
    id: string;
    type: AlertType;
    message: string;
    title?: string;
    actions?: AlertAction[];
    dismissible?: boolean;
    timeout?: number | false; // milliseconds, or false for no timeout
    key?: string; // for scoped alerts
}

interface AlertContextValue {
    alerts: Alert[];
    addAlert: (alert: Omit<Alert, 'id'>) => string;
    dismissAlert: (id: string) => void;
    clearAlerts: (key?: string) => void;
    // Convenience methods
    success: (message: string, options?: Partial<Omit<Alert, 'id' | 'type' | 'message'>>) => string;
    error: (message: string, options?: Partial<Omit<Alert, 'id' | 'type' | 'message'>>) => string;
    info: (message: string, options?: Partial<Omit<Alert, 'id' | 'type' | 'message'>>) => string;
    warning: (message: string, options?: Partial<Omit<Alert, 'id' | 'type' | 'message'>>) => string;
}

const AlertContext = createContext<AlertContextValue | undefined>(undefined);

export const useAlerts = () => {
    const context = useContext(AlertContext);
    if (!context) {
        throw new Error('useAlerts must be used within an AlertProvider');
    }
    return context;
};

interface AlertProviderProps {
    children: React.ReactNode;
}

export const AlertProvider: React.FC<AlertProviderProps> = ({ children }) => {
    const [alerts, setAlerts] = useState<Alert[]>([]);

    const addAlert = useCallback((alert: Omit<Alert, 'id'>): string => {
        const id = nanoid();
        const newAlert: Alert = {
            ...alert,
            id,
            dismissible: alert.dismissible !== undefined ? alert.dismissible : true,
            timeout: alert.timeout !== undefined ? alert.timeout : 5000, // default 5 seconds
        };

        setAlerts(prev => [...prev, newAlert]);
        return id;
    }, []);

    const dismissAlert = useCallback((id: string) => {
        setAlerts(prev => prev.filter(alert => alert.id !== id));
    }, []);

    const clearAlerts = useCallback((key?: string) => {
        if (key) {
            setAlerts(prev => prev.filter(alert => alert.key !== key));
        } else {
            setAlerts([]);
        }
    }, []);

    const success = useCallback(
        (message: string, options?: Partial<Omit<Alert, 'id' | 'type' | 'message'>>) => {
            return addAlert({ type: 'success', message, ...options });
        },
        [addAlert],
    );

    const error = useCallback(
        (message: string, options?: Partial<Omit<Alert, 'id' | 'type' | 'message'>>) => {
            return addAlert({ type: 'error', message, ...options });
        },
        [addAlert],
    );

    const info = useCallback(
        (message: string, options?: Partial<Omit<Alert, 'id' | 'type' | 'message'>>) => {
            return addAlert({ type: 'info', message, ...options });
        },
        [addAlert],
    );

    const warning = useCallback(
        (message: string, options?: Partial<Omit<Alert, 'id' | 'type' | 'message'>>) => {
            return addAlert({ type: 'warning', message, ...options });
        },
        [addAlert],
    );

    // Auto-dismiss alerts with timeout
    useEffect(() => {
        const timers: Record<string, NodeJS.Timeout> = {};

        alerts.forEach(alert => {
            if (alert.timeout && alert.timeout > 0 && !timers[alert.id]) {
                timers[alert.id] = setTimeout(() => {
                    dismissAlert(alert.id);
                }, alert.timeout);
            }
        });

        return () => {
            Object.values(timers).forEach(timer => clearTimeout(timer));
        };
    }, [alerts, dismissAlert]);

    const value: AlertContextValue = {
        alerts,
        addAlert,
        dismissAlert,
        clearAlerts,
        success,
        error,
        info,
        warning,
    };

    return <AlertContext.Provider value={value}>{children}</AlertContext.Provider>;
};
