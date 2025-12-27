/**
 * @deprecated Use useAlerts from @/contexts/AlertContext instead.
 * This file is kept for backward compatibility during migration.
 */
import { useAlerts } from '@/contexts/AlertContext';
import { httpErrorToHuman } from '@/api/http';

interface KeyedFlashStore {
    addError: (message: string, title?: string) => void;
    clearFlashes: () => void;
    clearAndAddHttpError: (error?: Error | string | null) => void;
}

/**
 * @deprecated Use useAlerts from @/contexts/AlertContext directly
 */
const useFlash = () => {
    return useAlerts();
};

/**
 * @deprecated Use useAlerts with key parameter instead
 */
const useFlashKey = (key: string): KeyedFlashStore => {
    const { addAlert, clearAlerts } = useAlerts();

    return {
        addError: (message, title) =>
            addAlert({
                key,
                message,
                title: title || 'Error',
                type: 'error',
            }),
        clearFlashes: () => clearAlerts(key),
        clearAndAddHttpError: error => {
            clearAlerts(key);
            if (error) {
                const message = httpErrorToHuman(error);
                addAlert({
                    key,
                    type: 'error',
                    title: 'Error',
                    message,
                });
            }
        },
    };
};

export { useFlashKey };
export default useFlash;
