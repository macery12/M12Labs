import { useState } from 'react';
import { WebhookEvent, toggleEventStatus } from '@/api/routes/admin/webhooks';
import { Switch } from '@headlessui/react';
import classNames from 'classnames';
import Spinner from '@/elements/Spinner';

interface Props {
    event: WebhookEvent;
    onUpdate: () => void;
}

export default ({ event, onUpdate }: Props) => {
    const [isLoading, setIsLoading] = useState(false);
    const [enabled, setEnabled] = useState(event.enabled);

    const handleToggle = async () => {
        setIsLoading(true);
        const newState = !enabled;

        try {
            await toggleEventStatus(newState, event.id);
            setEnabled(newState);
            onUpdate();
        } catch (error) {
            // Revert on error
            console.error('Failed to toggle webhook event:', error);
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <div
            className={classNames(
                'group relative rounded-lg border p-4 transition-all duration-200',
                enabled
                    ? 'border-green-500/30 bg-green-500/5 hover:border-green-500/50'
                    : 'bg-neutral-750 border-neutral-700 hover:border-neutral-600',
            )}
        >
            <div className={'flex items-start justify-between'}>
                <div className={'flex-1 pr-4'}>
                    <h4 className={'mb-2 font-medium capitalize text-neutral-100'}>
                        {event.key.split(':').slice(1).join(' - ').replace(/-/g, ' ')}
                    </h4>
                    <p className={'text-sm text-neutral-400'}>{event.description}</p>
                </div>

                <div className={'flex flex-col items-end space-y-2'}>
                    {isLoading ? (
                        <div className={'flex h-6 w-11 items-center justify-center'}>
                            <Spinner size={'small'} />
                        </div>
                    ) : (
                        <Switch
                            checked={enabled}
                            onChange={handleToggle}
                            className={classNames(
                                enabled ? 'bg-green-500' : 'bg-neutral-600',
                                'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 focus:ring-offset-neutral-800',
                            )}
                        >
                            <span className={'sr-only'}>Toggle webhook event</span>
                            <span
                                className={classNames(
                                    enabled ? 'translate-x-5' : 'translate-x-0',
                                    'pointer-events-none relative inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                                )}
                            />
                        </Switch>
                    )}

                    <span
                        className={classNames('text-xs font-medium', enabled ? 'text-green-400' : 'text-neutral-500')}
                    >
                        {enabled ? 'Enabled' : 'Disabled'}
                    </span>
                </div>
            </div>

            {/* Subtle indicator line at bottom */}
            <div
                className={classNames(
                    'absolute bottom-0 left-0 right-0 h-0.5 rounded-b-lg transition-opacity duration-200',
                    enabled ? 'bg-green-500 opacity-100' : 'bg-transparent opacity-0',
                )}
            />
        </div>
    );
};
