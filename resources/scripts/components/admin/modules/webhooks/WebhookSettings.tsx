import { Form, Formik } from 'formik';
import AdminBox from '@/elements/AdminBox';
import Field from '@/elements/Field';
import { Button } from '@/elements/button';
import { useStoreState } from '@/state/hooks';
import useFlash from '@/plugins/useFlash';
import { useEffect, useState } from 'react';
import FlashMessageRender from '@/elements/FlashMessageRender';
import Label from '@/elements/Label';
import { faLink, faCheck, faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';
import ToggleWebhooksButton from './ToggleWebhooksButton';
import { update, sendTestEvent } from '@/api/routes/admin/webhooks';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

export interface WebhookSettings {
    url: string;
}

export default () => {
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();
    const settings = useStoreState(state => state.everest.data!.webhooks);
    const [isTesting, setIsTesting] = useState(false);

    const submit = (values: WebhookSettings) => {
        clearFlashes();

        update('url', values.url)
            .then(() => {
                addFlash({
                    type: 'success',
                    key: 'admin:webhooks',
                    message: 'Webhook URL has been updated successfully.',
                });
            })
            .catch(error => {
                clearAndAddHttpError({
                    key: 'admin:webhooks',
                    error: error,
                });
            });
    };

    const handleTestWebhook = () => {
        if (!settings.url) {
            addFlash({
                type: 'danger',
                key: 'admin:webhooks',
                message: 'Please configure a webhook URL before testing.',
            });
            return;
        }

        setIsTesting(true);
        clearFlashes();

        sendTestEvent()
            .then(() => {
                addFlash({
                    key: 'admin:webhooks',
                    type: 'success',
                    message: 'Test webhook sent successfully! Check your configured endpoint.',
                });
            })
            .catch(error => clearAndAddHttpError({ key: 'admin:webhooks', error }))
            .finally(() => setIsTesting(false));
    };

    useEffect(() => {
        clearFlashes();
    }, []);

    return (
        <Formik
            onSubmit={submit}
            initialValues={{
                url: '',
            }}
        >
            <Form>
                <FlashMessageRender byKey={'admin:webhooks'} className={'mb-4'} />

                <div className={'mb-6 grid gap-6 lg:grid-cols-3'}>
                    {/* Webhook URL Configuration */}
                    <div className={'lg:col-span-2'}>
                        <AdminBox title={'Webhook URL Configuration'} icon={faLink}>
                            <div>
                                <Label className={'mb-2'}>Webhook Endpoint URL</Label>
                                <Field
                                    id={'url'}
                                    name={'url'}
                                    placeholder={
                                        settings.url
                                            ? 'The webhook URL has already been configured'
                                            : 'https://your-domain.com/webhook-endpoint'
                                    }
                                    description={
                                        'Set the webhook URL where events will be sent. This URL will receive POST requests with event data.'
                                    }
                                />
                                {settings.url && (
                                    <div className={'mt-3 rounded-lg border border-green-500/30 bg-green-500/10 p-3'}>
                                        <div className={'flex items-center space-x-2'}>
                                            <FontAwesomeIcon icon={faCheck} className={'text-green-400'} />
                                            <span className={'text-sm text-green-400'}>Webhook URL is configured</span>
                                        </div>
                                        <p className={'ml-6 mt-1 text-xs text-neutral-400'}>
                                            Current URL: {settings.url === true ? 'Configured (hidden)' : settings.url}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </AdminBox>
                    </div>

                    {/* Quick Actions */}
                    <div className={'space-y-6'}>
                        <AdminBox title={'Quick Actions'}>
                            <div className={'space-y-3'}>
                                <Button
                                    className={'w-full'}
                                    onClick={handleTestWebhook}
                                    disabled={isTesting || !settings.url}
                                    variant={Button.Variants.Secondary}
                                >
                                    {isTesting ? 'Sending Test...' : 'Send Test Webhook'}
                                </Button>
                                <ToggleWebhooksButton fullWidth />
                            </div>
                        </AdminBox>

                        <div className={'rounded-lg border border-blue-500/30 bg-blue-500/10 p-4'}>
                            <div className={'mb-2 flex items-center space-x-2'}>
                                <FontAwesomeIcon icon={faExclamationTriangle} className={'text-blue-400'} />
                                <span className={'text-sm font-medium text-blue-400'}>Integration Guide</span>
                            </div>
                            <p className={'text-xs text-neutral-400'}>
                                Webhooks send POST requests with JSON payloads. Ensure your endpoint can handle incoming
                                requests and returns a 200 status code.
                            </p>
                        </div>
                    </div>
                </div>

                {/* Webhook Format Information */}
                <AdminBox title={'Webhook Event Format'} className={'mb-6'}>
                    <div className={'space-y-3'}>
                        <p className={'text-sm text-neutral-400'}>
                            Each webhook event is sent as a POST request with the following structure:
                        </p>
                        <pre className={'rounded-lg bg-neutral-900 p-4 text-xs text-neutral-300'}>
                            {JSON.stringify(
                                {
                                    event: 'admin:servers:create',
                                    timestamp: '2024-01-01T12:00:00Z',
                                    data: {
                                        /* Event-specific data */
                                    },
                                },
                                null,
                                2,
                            )}
                        </pre>
                    </div>
                </AdminBox>

                {/* Save Button */}
                <div className={'flex items-center justify-between'}>
                    <div className={'text-sm text-neutral-500'}>Changes will apply immediately upon saving.</div>
                    <Button type="submit">Save Webhook URL</Button>
                </div>
            </Form>
        </Formik>
    );
};
