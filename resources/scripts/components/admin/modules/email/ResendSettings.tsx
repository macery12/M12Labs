import { useState, useEffect } from 'react';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import { faEnvelope, faCheckCircle } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from '@/state/hooks';
import useStatus from '@/plugins/useStatus';
import { updateSettings } from '@/api/routes/admin/email';
import Switch from '@/elements/Switch';
import { Button } from '@/elements/button';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const settings = useStoreState(state => state.everest.data!.email.resend);

    // Form state - controlled components
    const [enabled, setEnabled] = useState(settings.enabled);
    const [apiKey, setApiKey] = useState('');
    const [fromEmail, setFromEmail] = useState(settings.from_email || '');
    const [fromName, setFromName] = useState(settings.from_name || '');
    const [replyTo, setReplyTo] = useState(settings.reply_to || '');
    
    // Track if API key field has been touched
    const [apiKeyTouched, setApiKeyTouched] = useState(false);
    
    // Track if form has unsaved changes
    const [hasChanges, setHasChanges] = useState(false);

    // Initialize form when settings change (after save)
    useEffect(() => {
        setEnabled(settings.enabled);
        setFromEmail(settings.from_email || '');
        setFromName(settings.from_name || '');
        setReplyTo(settings.reply_to || '');
        // Don't reset API key field as it's password type
    }, [settings]);

    // Track changes
    useEffect(() => {
        const changed = 
            enabled !== settings.enabled ||
            (apiKeyTouched && apiKey !== '') ||
            fromEmail !== (settings.from_email || '') ||
            fromName !== (settings.from_name || '') ||
            replyTo !== (settings.reply_to || '');
        
        setHasChanges(changed);
    }, [enabled, apiKey, apiKeyTouched, fromEmail, fromName, replyTo, settings]);

    const handleSave = () => {
        clearFlashes();
        setStatus('loading');

        // Build update data - only include fields that should be updated
        const updateData: Record<string, any> = {
            enabled,
            from_email: fromEmail,
            from_name: fromName,
            reply_to: replyTo,
        };

        // Only include API key if user has entered a new one
        if (apiKeyTouched && apiKey) {
            updateData.api_key = apiKey;
        }

        updateSettings(updateData)
            .then(() => {
                setStatus('success');
                setHasChanges(false);
                setApiKeyTouched(false);
                setApiKey(''); // Clear the password field after successful save
                addFlash({
                    key: 'email:resend',
                    type: 'success',
                    message: 'Email settings saved successfully. Refreshing page...',
                });
                // Reload the page after a short delay to refresh the state
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            })
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
                    checked={enabled}
                    onChange={e => setEnabled(e.target.checked)}
                />
                <p className={'mt-1 text-xs text-gray-400'}>
                    Enable or disable the Resend email system.
                </p>
            </div>

            <div className={'mt-6'}>
                <Label>
                    API Key
                    {settings.api_key && (
                        <FontAwesomeIcon 
                            icon={faCheckCircle} 
                            className={'ml-2 text-green-500'} 
                            title="API key is configured"
                        />
                    )}
                </Label>
                <Input
                    placeholder={settings.api_key ? 'API key is set (leave empty to keep current)' : 're_xxxxxxxxxxxxxxxxxxxx'}
                    id={'api_key'}
                    type={'password'}
                    name={'api_key'}
                    autoComplete={'off'}
                    value={apiKey}
                    onChange={e => {
                        setApiKey(e.target.value);
                        setApiKeyTouched(true);
                    }}
                />
                <p className={'mt-1 text-xs text-gray-400'}>
                    {settings.api_key ? (
                        <span className={'text-green-400'}>
                            ✓ API key is configured. Leave empty to keep current, or enter a new key to replace it.
                        </span>
                    ) : (
                        <>
                            Your Resend API key. Get it from{' '}
                            <a
                                href="https://resend.com/api-keys"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-blue-400 hover:text-blue-300"
                            >
                                resend.com/api-keys
                            </a>
                        </>
                    )}
                </p>
                <p className={'mt-1 text-xs text-gray-500 italic'}>
                    🔒 API keys are encrypted in transit via HTTPS and stored securely in the database.
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
                    value={fromEmail}
                    onChange={e => setFromEmail(e.target.value)}
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
                    value={fromName}
                    onChange={e => setFromName(e.target.value)}
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
                    value={replyTo}
                    onChange={e => setReplyTo(e.target.value)}
                />
                <p className={'mt-1 text-xs text-gray-400'}>
                    Optional: Email address for replies.
                </p>
            </div>

            <div className={'mt-6 flex justify-end'}>
                <Button onClick={handleSave} disabled={!hasChanges}>
                    Save Changes
                </Button>
            </div>
        </AdminBox>
    );
};
