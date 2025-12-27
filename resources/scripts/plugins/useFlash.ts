import { Actions, useStoreActions } from 'easy-peasy';
import { FlashStore, FlashMessage } from '@/state/flashes';
import { ApplicationStore } from '@/state';
import { useAlerts } from '@/contexts/AlertContext';

interface KeyedFlashStore {
    addError: (message: string, title?: string) => void;
    clearFlashes: () => void;
    clearAndAddHttpError: (error?: Error | string | null) => void;
}

const useFlash = (): Actions<FlashStore> => {
    const flashActions = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);
    const { addAlert } = useAlerts();

    // Bridge flash messages to the new Alert Manager
    // This allows existing code to continue working while we migrate
    const originalAddFlash = flashActions.addFlash;

    // Override addFlash to also add to AlertManager
    const bridgedAddFlash = (flash: FlashMessage) => {
        originalAddFlash(flash);

        // Also add to new Alert Manager
        addAlert({
            type:
                flash.type === 'error'
                    ? 'error'
                    : flash.type === 'success'
                    ? 'success'
                    : flash.type === 'warning'
                    ? 'warning'
                    : 'info',
            message: flash.message,
            title: flash.title,
            key: flash.key,
        });
    };

    return {
        ...flashActions,
        addFlash: bridgedAddFlash,
    };
};

const useFlashKey = (key: string): KeyedFlashStore => {
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();

    return {
        addError: (message, title) => addFlash({ key, message, title, type: 'error' }),
        clearFlashes: () => clearFlashes(key),
        clearAndAddHttpError: error => clearAndAddHttpError({ key, error }),
    };
};

export { useFlashKey };
export default useFlash;
