import { useState, useCallback, useRef } from 'react';
import debounce from 'debounce';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import { faEnvelope } from '@fortawesome/free-solid-svg-icons';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from '@/state/hooks';
import useStatus from '@/plugins/useStatus';
import { updateSettings } from '@/api/routes/admin/email';
import Switch from '@/elements/Switch';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const settings = useStoreState(state => state.everest.data!.email.resend);

    const [enabled, setEnabled] = useState(settings.enabled);
    const [apiKeyModified, setApiKeyModified] = useState(false);

    const update = (key: string, value: any) => {
        clearFlashes();
        setStatus('loading');

        // Update local state immediately for enabled toggle
        if (key === 'enabled') {
            setEnabled(value);
        }

        // Don't send api_key if it hasn't been modified (still showing placeholder)
        const updateData: Record<string, any> = {};
        if (key === 'api_key' && !apiKeyModified) {
            // User hasn't modified the API key field, don't send it
            return;
        }
        
        updateData[key] = value;

        updateSettings(updateData)
            .then(() => setStatus('success'))
            .catch(error => {
                setStatus('error');
                clearAndAddHttpError({ key: 'email:resend', error });
            });
    };

    // Create debounced version of update function (500ms delay)
    // eslint-disable-next-line react-hooks/exhaustive-deps
    const debouncedUpdate = useCallback(
        debounce((key: string, value: any) => {
            update(key, value);
        }, 500),
        [apiKeyModified]
    );

    const handleApiKeyChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const value = e.target.value;
        setApiKeyModified(true);
        debouncedUpdate('api_key', value);
    };

    return (
        <AdminBox title={'Resend Email Settings'} icon={faEnvelope} byKey={'email:resend'} status={status}>
            <div>
                <Label>Enable Resend Email</Label>
                <Switch
                    name={'enabled'}
                    defaultChecked={enabled}
                    onChange={e => update('enabled', e.target.checked)}
                />
                <p className={'mt-1 text-xs text-gray-400'}>
                    Enable or disable the Resend email system.
                </p>
            </div>

            {enabled && (
                <>
                    <div className={'mt-6'}>
                        <Label>API Key</Label>
                        <Input
                            placeholder={'re_xxxxxxxxxxxxxxxxxxxx'}
                            id={'api_key'}
                            type={'password'}
                            name={'api_key'}
                            autoComplete={'off'}
                            defaultValue={settings.api_key ? '••••••••••••••••' : ''}
                            onChange={handleApiKeyChange}
                        />
                        <p className={'mt-1 text-xs text-gray-400'}>
                            Your Resend API key. Get it from{' '}
                            <a
                                href="https://resend.com/api-keys"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-blue-400 hover:text-blue-300"
                            >
                                resend.com/api-keys
                            </a>
                        </p>
                    </div>

                    <div className={'mt-6'}>
                        <Label>From Email</Label>
                        <Input
                            placeholder={'noreply@example.com'}
                            id={'from_email'}
                            type={'email'}
                            name={'from_email'}
                            autoComplete={'off'}
                            defaultValue={settings.from_email}
                            onChange={e => debouncedUpdate('from_email', e.target.value)}
                        />
                        <p className={'mt-1 text-xs text-gray-400'}>
                            The email address to send emails from. Must be verified in Resend.
                        </p>
                    </div>

                    <div className={'mt-6'}>
                        <Label>From Name</Label>
                        <Input
                            placeholder={'My App'}
                            id={'from_name'}
                            type={'text'}
                            name={'from_name'}
                            autoComplete={'off'}
                            defaultValue={settings.from_name}
                            onChange={e => debouncedUpdate('from_name', e.target.value)}
                        />
                        <p className={'mt-1 text-xs text-gray-400'}>
                            The name that will appear in the "From" field of emails.
                        </p>
                    </div>

                    <div className={'mt-6'}>
                        <Label>Reply-To Email</Label>
                        <Input
                            placeholder={'support@example.com'}
                            id={'reply_to'}
                            type={'email'}
                            name={'reply_to'}
                            autoComplete={'off'}
                            defaultValue={settings.reply_to}
                            onChange={e => debouncedUpdate('reply_to', e.target.value)}
                        />
                        <p className={'mt-1 text-xs text-gray-400'}>
                            Optional: Email address for replies.
                        </p>
                    </div>
                </>
            )}
        </AdminBox>
    );
};
