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
import { useStoreState } from '@/state/hooks';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const { secondary } = useStoreState((state) => state.theme.data!.colors);
    
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

    const StatusToggleButton = enabled ? Button.Success : Button.Danger;

    const toggleHint = 'Saves instantly when toggled';

    return (
        <AdminBox title={'Resend Email Settings'} icon={faEnvelope} status={status}>
            <div className={'grid grid-cols-1 gap-6'}>
                {/* Enable/Disable Toggle - Instant Save */}
                <div
                    className={'rounded-lg border border-neutral-700 p-4'}
                    style={{ backgroundColor: secondary }}
                >
                    <div className={'flex flex-col gap-3 md:flex-row md:items-center md:justify-between'}>
                        <div className={'flex items-center gap-3'}>
                            <span
                                className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm font-semibold ${
                                    enabled
                                        ? 'border-green-500/60 bg-green-950 text-green-100'
                                        : 'border-red-500/60 bg-red-950 text-red-100'
                                }`}
                            >
                                <span
                                    className={`h-2 w-2 rounded-full ${
                                        enabled ? 'bg-green-400' : 'bg-red-400'
                                    }`}
                                />
                                {enabled ? 'Email delivery is enabled' : 'Email delivery is disabled'}
                            </span>
                            <span className={'hidden text-sm text-gray-400 md:inline'}>{toggleHint}</span>
                        </div>
                        <div className={'flex items-center gap-2'}>
                            <StatusToggleButton onClick={toggleEnabled} disabled={savingEnabled} loading={savingEnabled}>
                                {enabled ? 'Enabled — click to disable' : 'Disabled — click to enable'}
                            </StatusToggleButton>
                        </div>
                    </div>
                    <p className={'mt-2 text-xs text-gray-400 md:hidden'}>{toggleHint}</p>
                </div>

                {/* API Key - Auto-Save */}
                <div
                    className={'space-y-2 rounded-lg border border-neutral-700 p-4'}
                    style={{ backgroundColor: secondary }}
                >
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
                        <p className={'mt-1 text-sm text-green-400'}>
                            <FontAwesomeIcon icon={faCheckCircle} className={'mr-1'} />
                            API key is configured
                        </p>
                    )}
                    <p className={'text-sm text-gray-400'}>
                        Auto-saves after 1 second — get your API key from{' '}
                        <a
                            href="https://resend.com/api-keys"
                            target="_blank"
                            rel="noopener noreferrer"
                            className={'text-blue-400 hover:text-blue-300'}
                        >
                            resend.com/api-keys
                        </a>
                    </p>
                    <p className={'text-sm text-yellow-400'}>
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
                    <div className={'mt-3 space-y-2 rounded-md border border-amber-500/40 bg-amber-900/30 p-3 text-sm'}>
                        <div className={'text-amber-100'}>
                            <strong className={'text-amber-200'}>Important:</strong> Verify this domain in Resend{' '}
                            <a
                                href="https://resend.com/domains"
                                target="_blank"
                                rel="noopener noreferrer"
                                className={'text-blue-300 hover:text-blue-200 underline'}
                            >
                                resend.com/domains
                            </a>{' '}
                            or sends will fail with “domain is invalid”.
                        </div>
                        <div className={'text-amber-100'}>
                            <strong className={'text-amber-200'}>Tip:</strong> Avoid <code>noreply@</code>; use a
                            monitored inbox like <code>support@</code> or <code>hello@</code> to reduce spam filtering.
                        </div>
                    </div>
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
