import { useCallback, useEffect, useState, type ReactNode } from 'react';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import { faEnvelope, faCheckCircle, faSpinner } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import useFlash from '@/plugins/useFlash';
import useStatus from '@/plugins/useStatus';
import { Button } from '@/elements/button';
import debounce from 'debounce';
import { useStoreState } from '@/state/hooks';
import { EmailSettings, EmailSettingsUpdate, EmailTransport, getSettings, updateSettings } from '@/api/routes/admin/email';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const { secondary } = useStoreState((state) => state.theme.data!.colors);

    const [settings, setSettings] = useState<EmailSettings | null>(null);
    const [loading, setLoading] = useState(true);

    // Shared state
    const [enabled, setEnabled] = useState(false);
    const [transport, setTransport] = useState<EmailTransport>('resend');

    // Resend state
    const [apiKey, setApiKey] = useState('');
    const [fromEmail, setFromEmail] = useState('');
    const [fromName, setFromName] = useState('');
    const [replyTo, setReplyTo] = useState('');

    // SMTP state
    const [smtpHost, setSmtpHost] = useState('');
    const [smtpPort, setSmtpPort] = useState('');
    const [smtpUsername, setSmtpUsername] = useState('');
    const [smtpPassword, setSmtpPassword] = useState('');
    const [smtpEncryption, setSmtpEncryption] = useState('');
    const [smtpFromEmail, setSmtpFromEmail] = useState('');
    const [smtpFromName, setSmtpFromName] = useState('');
    const [smtpReplyTo, setSmtpReplyTo] = useState('');

    const [savingEnabled, setSavingEnabled] = useState(false);
    const [savingApiKey, setSavingApiKey] = useState(false);
    const [savingTransport, setSavingTransport] = useState(false);

    useEffect(() => {
        setLoading(true);
        getSettings()
            .then((data) => {
                setSettings(data);
                setEnabled(data.enabled);
                setTransport(data.transport);

                setFromEmail(data.resend.from_email || '');
                setFromName(data.resend.from_name || '');
                setReplyTo(data.resend.reply_to || '');

                setSmtpHost(data.smtp.host || '');
                setSmtpPort(data.smtp.port || '');
                setSmtpUsername(data.smtp.username || '');
                setSmtpEncryption(data.smtp.encryption || '');
                setSmtpFromEmail(data.smtp.from_email || '');
                setSmtpFromName(data.smtp.from_name || '');
                setSmtpReplyTo(data.smtp.reply_to || '');
                setLoading(false);
            })
            .catch((error) => {
                setLoading(false);
                clearAndAddHttpError({ key: 'email:settings:load', error });
            });
    }, []);

    const hasResendChanges = settings
        ? fromEmail !== (settings.resend.from_email || '') ||
          fromName !== (settings.resend.from_name || '') ||
          replyTo !== (settings.resend.reply_to || '')
        : false;

    const hasSmtpChanges = settings
        ? smtpHost !== (settings.smtp.host || '') ||
          smtpPort.toString() !== (settings.smtp.port || '').toString() ||
          smtpUsername !== (settings.smtp.username || '') ||
          smtpEncryption !== (settings.smtp.encryption || '') ||
          smtpFromEmail !== (settings.smtp.from_email || '') ||
          smtpFromName !== (settings.smtp.from_name || '') ||
          smtpReplyTo !== (settings.smtp.reply_to || '') ||
          smtpPassword.trim().length > 0
        : false;

    const saveEnabledStatus = (newEnabled: boolean) => {
        setSavingEnabled(true);
        clearFlashes();

        updateSettings({ enabled: newEnabled })
            .then((updatedSettings) => {
                setSavingEnabled(false);
                setSettings(updatedSettings);
                setEnabled(updatedSettings.enabled);

                addFlash({
                    key: 'email:settings:enabled',
                    type: 'success',
                    message: `Email delivery ${newEnabled ? 'enabled' : 'disabled'} successfully`,
                });
            })
            .catch((error) => {
                setSavingEnabled(false);
                setEnabled(!newEnabled);
                clearAndAddHttpError({ key: 'email:settings:enabled', error });
            });
    };

    const toggleEnabled = () => {
        const newEnabled = !enabled;
        setEnabled(newEnabled);
        saveEnabledStatus(newEnabled);
    };

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
                    setApiKey('');

                    addFlash({
                        key: 'email:settings:apikey',
                        type: 'success',
                        message: 'API key saved successfully',
                    });
                })
                .catch((error) => {
                    setSavingApiKey(false);
                    clearAndAddHttpError({ key: 'email:settings:apikey', error });
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

    const handleSaveResend = () => {
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
                setFromEmail(updatedSettings.resend.from_email || '');
                setFromName(updatedSettings.resend.from_name || '');
                setReplyTo(updatedSettings.resend.reply_to || '');

                addFlash({
                    key: 'email:settings:resend',
                    type: 'success',
                    message: 'Resend settings saved successfully',
                });
            })
            .catch((error) => {
                setStatus('error');
                clearAndAddHttpError({ key: 'email:settings:resend', error });
            });
    };

    const handleSaveSmtp = () => {
        clearFlashes();
        setStatus('processing');
        const payload: EmailSettingsUpdate = {
            smtp_host: smtpHost,
            smtp_port: smtpPort,
            smtp_username: smtpUsername,
            smtp_encryption: smtpEncryption,
            smtp_from_email: smtpFromEmail,
            smtp_from_name: smtpFromName,
            smtp_reply_to: smtpReplyTo,
        };

        if (smtpPassword.trim()) {
            payload.smtp_password = smtpPassword;
        }

        updateSettings(payload)
            .then((updatedSettings) => {
                setStatus('success');
                setSettings(updatedSettings);
                setSmtpHost(updatedSettings.smtp.host || '');
                setSmtpPort(updatedSettings.smtp.port || '');
                setSmtpUsername(updatedSettings.smtp.username || '');
                setSmtpEncryption(updatedSettings.smtp.encryption || '');
                setSmtpFromEmail(updatedSettings.smtp.from_email || '');
                setSmtpFromName(updatedSettings.smtp.from_name || '');
                setSmtpReplyTo(updatedSettings.smtp.reply_to || '');
                setSmtpPassword('');

                addFlash({
                    key: 'email:settings:smtp',
                    type: 'success',
                    message: 'SMTP settings saved successfully',
                });
            })
            .catch((error) => {
                setStatus('error');
                clearAndAddHttpError({ key: 'email:settings:smtp', error });
            });
    };

    const handleTransportChange = (value: EmailTransport) => {
        setSavingTransport(true);
        clearFlashes();

        updateSettings({ transport: value })
            .then((updated) => {
                setSavingTransport(false);
                setSettings(updated);
                setTransport(updated.transport);

                addFlash({
                    key: 'email:settings:transport',
                    type: 'success',
                    message: `Active transport switched to ${value.toUpperCase()}`,
                });
            })
            .catch((error) => {
                setSavingTransport(false);
                clearAndAddHttpError({ key: 'email:settings:transport', error });
            });
    };

    if (loading || !settings) {
        return (
            <AdminBox title={'Email Settings'} icon={faEnvelope}>
                <div className={'flex items-center justify-center py-8'}>
                    <FontAwesomeIcon icon={faSpinner} className={'fa-spin text-gray-400'} size={'2x'} />
                </div>
            </AdminBox>
        );
    }

    const StatusToggleButton = enabled ? Button.Success : Button.Danger;
    const toggleHint = 'Saves instantly when toggled';
    const isResendActive = transport === 'resend';
    const isSmtpActive = transport === 'smtp';

    return (
        <AdminBox title={'Email Settings'} icon={faEnvelope} status={status}>
            <div className={'grid grid-cols-1 gap-6'}>
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
                                    className={`h-2 w-2 rounded-full ${enabled ? 'bg-green-400' : 'bg-red-400'}`}
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

                <div className={'rounded-lg border border-neutral-700 p-4'} style={{ backgroundColor: secondary }}>
                    <div className={'flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between'}>
                        <div>
                            <Label>Active Transport</Label>
                            <p className={'text-sm text-gray-400'}>Choose how emails are delivered.</p>
                        </div>
                        <select
                            className={
                                'w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-white sm:w-auto'
                            }
                            value={transport}
                            onChange={(e) => handleTransportChange(e.target.value as EmailTransport)}
                            disabled={savingTransport}
                        >
                            <option value={'resend'}>Resend</option>
                            <option value={'smtp'}>SMTP</option>
                        </select>
                    </div>
                </div>

                <TransportSection
                    title={'Resend'}
                    active={isResendActive}
                    secondary={secondary}
                    badgeText={isResendActive ? 'Active' : 'Inactive'}
                >
                    <div
                        className={'space-y-2 rounded-lg border border-neutral-700 p-4'}
                        style={{ backgroundColor: secondary }}
                    >
                        <Label>
                            API Key
                            {settings?.resend.api_key && (
                                <FontAwesomeIcon
                                    icon={faCheckCircle}
                                    className={'ml-2 text-green-500'}
                                    title="API key is configured"
                                />
                            )}
                            {savingApiKey && <FontAwesomeIcon icon={faSpinner} className={'ml-2 fa-spin text-blue-500'} />}
                        </Label>
                        <Input
                            type={'password'}
                            value={apiKey}
                            onChange={handleApiKeyChange}
                            disabled={savingApiKey || !enabled}
                            placeholder={
                                settings?.resend.api_key
                                    ? 'API key is configured - enter a new key to replace it'
                                    : 'Enter your Resend API key'
                            }
                        />
                        {settings?.resend.api_key && (
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
                    </div>

                    <div>
                        <Label>
                            From Email <span className={'text-red-500'}>*</span>
                        </Label>
                        <Input
                            type={'email'}
                            value={fromEmail}
                            onChange={(e) => setFromEmail(e.target.value)}
                            placeholder={'noreply@yourdomain.com'}
                            disabled={!enabled}
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

                    <div>
                        <Label>From Name</Label>
                        <Input
                            value={fromName}
                            onChange={(e) => setFromName(e.target.value)}
                            placeholder={'Your App Name'}
                            disabled={!enabled}
                        />
                        <p className={'mt-2 text-sm text-gray-400'}>The name that appears in the "From" field of emails</p>
                    </div>

                    <div>
                        <Label>Reply-To Email</Label>
                        <Input
                            type={'email'}
                            value={replyTo}
                            onChange={(e) => setReplyTo(e.target.value)}
                            placeholder={'support@yourdomain.com'}
                            disabled={!enabled}
                        />
                        <p className={'mt-2 text-sm text-gray-400'}>
                            Email address where replies will be sent (optional)
                        </p>
                    </div>

                    <div className={'flex items-center gap-3'}>
                        <Button onClick={handleSaveResend} disabled={!hasResendChanges || status === 'processing'}>
                            Save Resend Settings
                        </Button>
                        {!hasResendChanges && (
                            <p className={'text-sm text-gray-400'}>
                                No changes to save
                            </p>
                        )}
                    </div>
                </TransportSection>

                <TransportSection
                    title={'SMTP'}
                    active={isSmtpActive}
                    secondary={secondary}
                    badgeText={isSmtpActive ? 'Active' : 'Inactive'}
                >
                    <div className={'grid grid-cols-1 gap-4 sm:grid-cols-2'}>
                        <div>
                            <Label>Host</Label>
                            <Input
                                value={smtpHost}
                                onChange={(e) => setSmtpHost(e.target.value)}
                                placeholder={'smtp.yourdomain.com'}
                                disabled={!enabled}
                            />
                        </div>
                        <div>
                            <Label>Port</Label>
                            <Input
                                type={'number'}
                                value={smtpPort}
                                onChange={(e) => setSmtpPort(e.target.value)}
                                placeholder={'587'}
                                disabled={!enabled}
                            />
                        </div>
                        <div>
                            <Label>Username</Label>
                            <Input
                                value={smtpUsername}
                                onChange={(e) => setSmtpUsername(e.target.value)}
                                placeholder={'smtp username'}
                                disabled={!enabled}
                            />
                        </div>
                        <div>
                            <Label>Password</Label>
                            <Input
                                type={'password'}
                                value={smtpPassword}
                                onChange={(e) => setSmtpPassword(e.target.value)}
                                placeholder={
                                    settings.smtp.password_set
                                        ? '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022 (configured)'
                                        : 'SMTP password'
                                }
                                disabled={!enabled}
                            />
                            <p className={'mt-1 text-xs text-gray-400'}>
                                Leave blank to keep the existing password.
                            </p>
                        </div>
                        <div>
                            <Label>Encryption</Label>
                            <select
                                className={
                                    'w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-white'
                                }
                                value={smtpEncryption}
                                onChange={(e) => setSmtpEncryption(e.target.value)}
                                disabled={!enabled}
                            >
                                <option value=''>None</option>
                                <option value='tls'>TLS</option>
                                <option value='ssl'>SSL</option>
                            </select>
                        </div>
                    </div>

                    <div className={'grid grid-cols-1 gap-4 sm:grid-cols-2'}>
                        <div>
                            <Label>
                                From Email <span className={'text-red-500'}>*</span>
                            </Label>
                            <Input
                                type={'email'}
                                value={smtpFromEmail}
                                onChange={(e) => setSmtpFromEmail(e.target.value)}
                                placeholder={'noreply@yourdomain.com'}
                                disabled={!enabled}
                            />
                        </div>
                        <div>
                            <Label>From Name</Label>
                            <Input
                                value={smtpFromName}
                                onChange={(e) => setSmtpFromName(e.target.value)}
                                placeholder={'Your App Name'}
                                disabled={!enabled}
                            />
                        </div>
                        <div>
                            <Label>Reply-To Email</Label>
                            <Input
                                type={'email'}
                                value={smtpReplyTo}
                                onChange={(e) => setSmtpReplyTo(e.target.value)}
                                placeholder={'support@yourdomain.com'}
                                disabled={!enabled}
                            />
                        </div>
                    </div>

                    <div className={'flex items-center gap-3'}>
                        <Button onClick={handleSaveSmtp} disabled={!hasSmtpChanges || status === 'processing'}>
                            Save SMTP Settings
                        </Button>
                        {!hasSmtpChanges && (
                            <p className={'text-sm text-gray-400'}>
                                No changes to save
                            </p>
                        )}
                    </div>
                </TransportSection>
            </div>
        </AdminBox>
    );
};

const TransportSection = ({
    title,
    active,
    children,
    secondary,
    badgeText,
}: {
    title: string;
    active: boolean;
    children: ReactNode;
    secondary: string;
    badgeText: string;
}) => {
    return (
        <div className={'space-y-4 rounded-lg border border-neutral-700 p-4'} style={{ backgroundColor: secondary }}>
            <div className={'flex items-center justify-between'}>
                <div className={'flex items-center gap-2'}>
                    <h3 className={'text-lg font-semibold text-white'}>{title}</h3>
                    <span
                        className={`rounded-full px-2 py-1 text-xs font-semibold ${
                            active ? 'bg-green-900 text-green-200' : 'bg-neutral-800 text-neutral-200'
                        }`}
                    >
                        {badgeText}
                    </span>
                </div>
                {!active && <span className={'text-xs text-gray-400'}>Inactive transport</span>}
            </div>
            <div className={'space-y-4'}>{children}</div>
        </div>
    );
};
