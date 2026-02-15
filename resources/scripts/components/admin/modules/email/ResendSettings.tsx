import { useState } from 'react';
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

    const update = (key: string, value: any) => {
        clearFlashes();
        setStatus('loading');

        // Update local state immediately for enabled toggle
        if (key === 'enabled') {
            setEnabled(value);
        }

        updateSettings({ [key]: value })
            .then(() => setStatus('success'))
            .catch(error => {
                setStatus('error');
                clearAndAddHttpError({ key: 'email:resend', error });
            });
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
                            onChange={e => update('api_key', e.target.value)}
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
                            onChange={e => update('from_email', e.target.value)}
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
                            onChange={e => update('from_name', e.target.value)}
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
                            onChange={e => update('reply_to', e.target.value)}
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
