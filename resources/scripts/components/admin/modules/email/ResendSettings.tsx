import { useState, useEffect, useCallback } from 'react';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import { faEnvelope, faCheckCircle, faSpinner } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import useFlash from '@/plugins/useFlash';
import useStatus from '@/plugins/useStatus';
import { getSettings, updateSettings, ResendSettings } from '@/api/routes/admin/email';
import { Button } from '@/elements/button';
import debounce from 'debounce';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    
    // Settings loaded from API (not everest state)
    const [settings, setSettings] = useState<ResendSettings | null>(null);
    const [loading, setLoading] = useState(true);

    // Form state - controlled components
    const [enabled, setEnabled] = useState(false);
    const [apiKey, setApiKey] = useState('');
    const [fromEmail, setFromEmail] = useState('');
    const [fromName, setFromName] = useState('');
    const [replyTo, setReplyTo] = useState('');

    // Loading states for individual save operations
    const [savingEnabled, setSavingEnabled] = useState(false);
    const [savingApiKey, setSavingApiKey] = useState(false);

    // Load settings from API on mount
    useEffect(() => {
        setLoading(true);
        getSettings()
            .then((data) => {
                setSettings(data);
                setEnabled(data.enabled);
                setFromEmail(data.from_email || '');
                setFromName(data.from_name || '');
                setReplyTo(data.reply_to || '');
                setLoading(false);
            })
            .catch((error) => {
                setLoading(false);
                clearAndAddHttpError({ key: 'email:resend:load', error });
            });
    }, []);

    // Change detection - ONLY for from_email, from_name, reply_to (Save Settings button)
    const hasFormChanges = settings
        ? fromEmail !== (settings.from_email || '') ||
          fromName !== (settings.from_name || '') ||
          replyTo !== (settings.reply_to || '')
        : false;

    // Save enabled/disabled status immediately
    const saveEnabledStatus = (newEnabled: boolean) => {
        setSavingEnabled(true);
        clearFlashes();

        updateSettings({ enabled: newEnabled })
            .then((updatedSettings) => {
                setSavingEnabled(false);
                setSettings(updatedSettings);
                setEnabled(updatedSettings.enabled);

                addFlash({
                    key: 'email:resend:enabled',
                    type: 'success',
                    message: `Email system ${newEnabled ? 'enabled' : 'disabled'} successfully`,
                });
            })
            .catch((error) => {
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

    // Auto-save API key with debounce
    const saveApiKeyDebounced = useCallback(
        debounce((key: string) => {
            if (!key.trim()) {
                setSavingApiKey(false);
                return;
            }

            updateSettings({ api_key: key })
                .then((updatedSettings) => {
                    setSavingApiKey(false);
                    setSettings(updatedSettings);
                    setApiKey(''); // Clear for security

                    addFlash({
                        key: 'email:resend:apikey',
                        type: 'success',
                        message: 'API key saved successfully',
                    });
                })
                .catch((error) => {
                    setSavingApiKey(false);
                    clearAndAddHttpError({ key: 'email:resend:apikey', error });
                });
        }, 1000),
        []
    );

    const handleApiKeyChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const value = e.target.value;
        setApiKey(value);
        
        if (value.trim()) {
            setSavingApiKey(true);
            saveApiKeyDebounced(value);
        }
    };

    // Save other settings (from_email, from_name, reply_to) via button
    const handleSaveSettings = () => {
        clearFlashes();
        setStatus('processing');

        updateSettings({
            from_email: fromEmail,
            from_name: fromName,
            reply_to: replyTo,
        })
            .then((updatedSettings) => {
                setStatus('success');
                setSettings(updatedSettings);
                setFromEmail(updatedSettings.from_email || '');
                setFromName(updatedSettings.from_name || '');
                setReplyTo(updatedSettings.reply_to || '');

                addFlash({
                    key: 'email:resend:settings',
                    type: 'success',
                    message: 'Email settings saved successfully',
                });
            })
            .catch((error) => {
                setStatus('error');
                clearAndAddHttpError({ key: 'email:resend:settings', error });
            });
    };

    if (loading) {
        return (
            <AdminBox title={'Resend Email Settings'} icon={faEnvelope}>
                <div className={'flex items-center justify-center py-8'}>
                    <FontAwesomeIcon icon={faSpinner} className={'fa-spin text-gray-400'} size={'2x'} />
                </div>
            </AdminBox>
        );
    }

    return (
        <AdminBox title={'Resend Email Settings'} icon={faEnvelope} status={status}>
            <div className={'grid grid-cols-1 gap-6'}>
                {/* Enable/Disable Toggle - Instant Save */}
                <div>
                    <Label>Status</Label>
                    <div className={'flex items-center gap-4'}>
                        <Button
                            onClick={toggleEnabled}
                            disabled={savingEnabled}
                            className={enabled ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'}
                        >
                            {savingEnabled && (
                                <FontAwesomeIcon icon={faSpinner} className={'fa-spin mr-2'} />
                            )}
                            {savingEnabled ? 'Saving...' : enabled ? 'Enabled' : 'Disabled'}
                        </Button>
                        <p className={'text-sm text-gray-400'}>
                            {enabled ? 'Email system is active' : 'Email system is inactive'} - saves instantly
                        </p>
                    </div>
                </div>

                {/* API Key - Auto-Save */}
                <div>
                    <Label>
                        API Key
                        {settings?.api_key && (
                            <FontAwesomeIcon
                                icon={faCheckCircle}
                                className={'ml-2 text-green-500'}
                                title="API key is configured"
                            />
                        )}
                        {savingApiKey && (
                            <FontAwesomeIcon
                                icon={faSpinner}
                                className={'ml-2 fa-spin text-blue-500'}
                            />
                        )}
                    </Label>
                    <Input
                        type={'password'}
                        value={apiKey}
                        onChange={handleApiKeyChange}
                        disabled={savingApiKey}
                        placeholder={
                            settings?.api_key
                                ? 'API key is configured - enter a new key to replace it'
                                : 'Enter your Resend API key'
                        }
                    />
                    {settings?.api_key && (
                        <p className={'mt-2 text-sm text-green-400'}>
                            <FontAwesomeIcon icon={faCheckCircle} className={'mr-1'} />
                            API key is configured
                        </p>
                    )}
                    <p className={'mt-2 text-sm text-gray-400'}>
                        Auto-saves after 1 second - Get your API key from{' '}
                        <a
                            href="https://resend.com/api-keys"
                            target="_blank"
                            rel="noopener noreferrer"
                            className={'text-blue-400 hover:text-blue-300'}
                        >
                            resend.com/api-keys
                        </a>
                    </p>
                    <p className={'mt-2 text-sm text-yellow-400'}>
                        🔒 API keys are encrypted in transit via HTTPS and stored securely in the database
                    </p>
                </div>

                <hr className={'border-gray-700'} />

                <p className={'text-sm text-gray-400'}>
                    <strong>Email Configuration</strong> - Use the Save Settings button below
                </p>

                {/* From Email - Manual Save */}
                <div>
                    <Label>
                        From Email <span className={'text-red-500'}>*</span>
                    </Label>
                    <Input
                        type={'email'}
                        value={fromEmail}
                        onChange={(e) => setFromEmail(e.target.value)}
                        placeholder={'noreply@yourdomain.com'}
                    />
                    <p className={'mt-2 text-sm text-yellow-400'}>
                        ⚠️ <strong>Important:</strong> The domain of this email must be verified in your Resend
                        account at{' '}
                        <a
                            href="https://resend.com/domains"
                            target="_blank"
                            rel="noopener noreferrer"
                            className={'text-blue-400 hover:text-blue-300'}
                        >
                            resend.com/domains
                        </a>{' '}
                        or emails will fail with "domain is invalid" error. For example, if your email is
                        "noreply@example.com", you must verify the domain "example.com" in Resend.
                    </p>
                </div>

                {/* From Name - Manual Save */}
                <div>
                    <Label>From Name</Label>
                    <Input
                        value={fromName}
                        onChange={(e) => setFromName(e.target.value)}
                        placeholder={'Your App Name'}
                    />
                    <p className={'mt-2 text-sm text-gray-400'}>
                        The name that appears in the "From" field of emails
                    </p>
                </div>

                {/* Reply-To Email - Manual Save */}
                <div>
                    <Label>Reply-To Email</Label>
                    <Input
                        type={'email'}
                        value={replyTo}
                        onChange={(e) => setReplyTo(e.target.value)}
                        placeholder={'support@yourdomain.com'}
                    />
                    <p className={'mt-2 text-sm text-gray-400'}>
                        Email address where replies will be sent (optional)
                    </p>
                </div>

                {/* Save Settings Button - Manual Save */}
                <div>
                    <Button onClick={handleSaveSettings} disabled={!hasFormChanges || status === 'processing'}>
                        Save Settings
                    </Button>
                    {!hasFormChanges && (
                        <p className={'mt-2 text-sm text-gray-400'}>
                            No changes to save
                        </p>
                    )}
                </div>
            </div>
        </AdminBox>
    );
};
