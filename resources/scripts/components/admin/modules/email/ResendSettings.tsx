import { useState, useEffect, useCallback, useRef } from 'react';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import { faEnvelope, faCheckCircle, faSpinner } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import useFlash from '@/plugins/useFlash';
import { useStoreState, useStoreActions } from '@/state/hooks';
import useStatus from '@/plugins/useStatus';
import { updateSettings } from '@/api/routes/admin/email';
import { Button } from '@/elements/button';
import debounce from 'debounce';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const settings = useStoreState(state => state.everest.data!.email.resend);
    const updateEverest = useStoreActions(actions => actions.everest.updateEverest);

    // Form state - controlled components
    const [enabled, setEnabled] = useState(settings.enabled);
    const [apiKey, setApiKey] = useState('');
    const [fromEmail, setFromEmail] = useState(settings.from_email || '');
    const [fromName, setFromName] = useState(settings.from_name || '');
    const [replyTo, setReplyTo] = useState(settings.reply_to || '');

    // Loading states for individual save operations
    const [savingEnabled, setSavingEnabled] = useState(false);
    const [savingApiKey, setSavingApiKey] = useState(false);

    // Initialize form when settings change
    useEffect(() => {
        setEnabled(settings.enabled);
        setFromEmail(settings.from_email || '');
        setFromName(settings.from_name || '');
        setReplyTo(settings.reply_to || '');
    }, [settings]);

    // Change detection - ONLY for from_email, from_name, reply_to (Save Settings button)
    const hasFormChanges = 
        fromEmail !== (settings.from_email || '') ||
        fromName !== (settings.from_name || '') ||
        replyTo !== (settings.reply_to || '');

    // Save enabled/disabled status immediately
    const saveEnabledStatus = (newEnabled: boolean) => {
        setSavingEnabled(true);
        clearFlashes();

        updateSettings({ enabled: newEnabled })
            .then(() => {
                setSavingEnabled(false);
                
                // Update global state immediately
                updateEverest({
                    email: {
                        resend: {
                            ...settings,
                            enabled: newEnabled,
                        },
                    },
                });

                addFlash({
                    key: 'email:resend:enabled',
                    type: 'success',
                    message: `Email system ${newEnabled ? 'enabled' : 'disabled'} successfully`,
                });
            })
            .catch(error => {
                setSavingEnabled(false);
                // Revert the toggle on error
                setEnabled(!newEnabled);
                clearAndAddHttpError({ key: 'email:resend:enabled', error });
            });
    };

    // Toggle enabled/disabled with instant save
    const toggleEnabled = () => {
        const newEnabled = !enabled;
        setEnabled(newEnabled);
        saveEnabledStatus(newEnabled);
    };

    // Debounced auto-save for API key
    const saveApiKeyDebounced = useCallback(
        debounce((key: string) => {
            if (!key.trim()) return; // Don't save empty values

            setSavingApiKey(true);
            clearFlashes();

            updateSettings({ api_key: key })
                .then(() => {
                    setSavingApiKey(false);
                    setApiKey(''); // Clear field after save for security
                    
                    // Update global state
                    updateEverest({
                        email: {
                            resend: {
                                ...settings,
                                api_key: true, // Mark as configured
                            },
                        },
                    });

                    addFlash({
                        key: 'email:resend:apikey',
                        type: 'success',
                        message: 'API key saved successfully',
                    });
                })
                .catch(error => {
                    setSavingApiKey(false);
                    clearAndAddHttpError({ key: 'email:resend:apikey', error });
                });
        }, 1000),
        [settings]
    );

    // Handle API key change with auto-save
    const handleApiKeyChange = (value: string) => {
        setApiKey(value);
        if (value.trim()) {
            saveApiKeyDebounced(value);
        }
    };

    // Save other settings (from_email, from_name, reply_to) via button
    const handleSaveSettings = () => {
        clearFlashes();
        setStatus('loading');

        const updateData = {
            from_email: fromEmail,
            from_name: fromName,
            reply_to: replyTo,
        };

        updateSettings(updateData)
            .then(() => {
                setStatus('success');
                
                // Update global state immediately
                updateEverest({
                    email: {
                        resend: {
                            ...settings,
                            from_email: fromEmail,
                            from_name: fromName,
                            reply_to: replyTo,
                        },
                    },
                });

                addFlash({
                    key: 'email:resend',
                    type: 'success',
                    message: 'Email settings saved successfully',
                });
            })
            .catch(error => {
                setStatus('error');
                clearAndAddHttpError({ key: 'email:resend', error });
            });
    };

    return (
        <AdminBox title={'Resend Email Settings'} icon={faEnvelope} byKey={'email:resend'} status={status}>
            <div>
                <Label>Email System Status</Label>
                <div className={'flex items-center gap-4'}>
                    <Button
                        onClick={toggleEnabled}
                        disabled={savingEnabled}
                        className={enabled ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'}
                    >
                        {savingEnabled && <FontAwesomeIcon icon={faSpinner} className={'mr-2 animate-spin'} />}
                        {enabled ? 'Enabled' : 'Disabled'}
                    </Button>
                    <span className={'text-sm text-gray-400'}>
                        {savingEnabled ? (
                            'Saving...'
                        ) : (
                            <>Click to {enabled ? 'disable' : 'enable'} the Resend email system (saves instantly)</>
                        )}
                    </span>
                </div>
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
                    {savingApiKey && (
                        <FontAwesomeIcon 
                            icon={faSpinner} 
                            className={'ml-2 text-blue-500 animate-spin'} 
                            title="Saving API key..."
                        />
                    )}
                </Label>
                <Input
                    placeholder={settings.api_key ? 'API key is set (enter new key to replace)' : 're_xxxxxxxxxxxxxxxxxxxx'}
                    id={'api_key'}
                    type={'password'}
                    name={'api_key'}
                    autoComplete={'off'}
                    value={apiKey}
                    onChange={e => handleApiKeyChange(e.target.value)}
                    disabled={savingApiKey}
                />
                <p className={'mt-1 text-xs text-gray-400'}>
                    {settings.api_key ? (
                        <span className={'text-green-400'}>
                            ✓ API key is configured. Enter a new key to replace it. <strong>Auto-saves after 1 second.</strong>
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
                            {' '}<strong>Auto-saves after 1 second.</strong>
                        </>
                    )}
                </p>
                <p className={'mt-1 text-xs text-gray-500 italic'}>
                    🔒 API keys are encrypted in transit via HTTPS and stored securely in the database.
                </p>
            </div>

            <hr className={'my-8 border-gray-700'} />
            
            <div className={'text-sm text-gray-400 mb-4'}>
                <strong>Email Configuration</strong> - Use the Save Settings button below to save these fields
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
                <Button onClick={handleSaveSettings} disabled={!hasFormChanges}>
                    Save Settings
                </Button>
            </div>
        </AdminBox>
    );
};
