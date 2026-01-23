import { useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import { Button } from '@/elements/button';
import { useStoreState, useStoreActions } from '@/state/hooks';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCheckCircle, faTimesCircle, faPuzzlePiece } from '@fortawesome/free-solid-svg-icons';
import { createIntegrationRegistry } from './registry';
import { updateSettings } from '@/api/routes/admin/billing';
import FlashMessageRender from '@/elements/FlashMessageRender';

export default () => {
    const settings = useStoreState(s => s.everest.data!.billing);
    const updateEverest = useStoreActions(s => s.everest.updateEverest);
    const [loading, setLoading] = useState<string | null>(null);

    const integrations = createIntegrationRegistry(settings);

    const toggleIntegration = async (integrationId: string, currentState: boolean) => {
        setLoading(integrationId);
        try {
            const newState = !currentState;
            await updateSettings(`integrations:${integrationId}:enabled`, newState);
            
            // Update local state
            const newIntegrations = {
                ...settings.integrations,
                [integrationId]: {
                    ...settings.integrations?.[integrationId],
                    enabled: newState,
                },
            };
            
            updateEverest({
                billing: {
                    ...settings,
                    integrations: newIntegrations,
                },
            });
        } catch (error) {
            console.error('Failed to toggle integration:', error);
        } finally {
            setLoading(null);
        }
    };

    return (
        <div>
            <FlashMessageRender byKey={'admin:billing:integrations'} className={'mb-4'} />
            
            <div className={'mb-6'}>
                <h3 className={'text-xl font-medium text-neutral-50 mb-2'}>Payment Integrations</h3>
                <p className={'text-base text-neutral-400'}>
                    Enable and configure payment integrations for your billing system. Multiple integrations can be
                    enabled simultaneously to provide more payment options to your users.
                </p>
            </div>

            <div className={'grid gap-4 lg:grid-cols-2'}>
                {integrations.map(integration => (
                    <AdminBox
                        key={integration.id}
                        title={integration.name}
                        icon={integration.icon}
                        className={'relative'}
                    >
                        <div className={'absolute top-4 right-4'}>
                            {integration.enabled ? (
                                <FontAwesomeIcon icon={faCheckCircle} className={'text-green-500 text-xl'} />
                            ) : (
                                <FontAwesomeIcon icon={faTimesCircle} className={'text-gray-500 text-xl'} />
                            )}
                        </div>

                        <p className={'text-sm text-neutral-400 mb-4'}>{integration.description}</p>

                        <div className={'mb-4'}>
                            <div className={'flex items-center gap-2 mb-2'}>
                                <span className={'text-sm font-medium text-neutral-300'}>Status:</span>
                                <span
                                    className={`text-sm ${
                                        integration.enabled ? 'text-green-500' : 'text-gray-500'
                                    }`}
                                >
                                    {integration.enabled ? 'Enabled' : 'Disabled'}
                                </span>
                            </div>
                            <div className={'flex items-center gap-2'}>
                                <span className={'text-sm font-medium text-neutral-300'}>Configuration:</span>
                                <span
                                    className={`text-sm ${
                                        integration.configured ? 'text-green-500' : 'text-yellow-500'
                                    }`}
                                >
                                    {integration.configured ? 'Complete' : 'Incomplete'}
                                </span>
                            </div>
                        </div>

                        <div className={'flex gap-2'}>
                            <Button
                                onClick={() => toggleIntegration(integration.id, integration.enabled)}
                                disabled={loading === integration.id}
                                variant={integration.enabled ? Button.Variants.Secondary : Button.Variants.Primary}
                            >
                                {loading === integration.id
                                    ? 'Loading...'
                                    : integration.enabled
                                    ? 'Disable'
                                    : 'Enable'}
                            </Button>
                            
                            {integration.enabled && !integration.configured && (
                                <p className={'text-xs text-yellow-500 flex items-center'}>
                                    ⚠️ Configure in the {integration.name} tab to start accepting payments
                                </p>
                            )}
                        </div>
                    </AdminBox>
                ))}
            </div>

            <div className={'mt-8 rounded-lg bg-blue-900/20 border border-blue-800/30 p-4'}>
                <div className={'flex items-start gap-3'}>
                    <FontAwesomeIcon icon={faPuzzlePiece} className={'text-blue-400 text-xl mt-1'} />
                    <div>
                        <h4 className={'text-base font-medium text-blue-300 mb-1'}>Adding New Integrations</h4>
                        <p className={'text-sm text-blue-200/80'}>
                            This modular system makes it easy to add new payment integrations in the future. Each
                            integration can be enabled independently and has its own configuration tab when enabled.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
};
