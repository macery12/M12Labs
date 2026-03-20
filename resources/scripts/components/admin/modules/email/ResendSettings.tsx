import { useEffect, useState, type ReactNode } from 'react';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import { faEnvelope, faCheckCircle, faSpinner } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import useFlash from '@/plugins/useFlash';
import useStatus from '@/plugins/useStatus';
import { Button } from '@/elements/button';
import { useStoreState } from '@/state/hooks';
import { EmailSettings, EmailSettingsUpdate, EmailTransport, getSettings, updateSettings } from '@/api/routes/admin/email';
import SendTestEmail from '@/components/admin/modules/email/SendTestEmail';

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const { secondary } = useStoreState((state) => state.theme.data!.colors);

    const [settings, setSettings] = useState<EmailSettings | null>(null);
    const [loading, setLoading] = useState(true);

    // Shared state
    const [enabled, setEnabled] = useState(false);
    const [transport, setTransport] = useState<EmailTransport>('smtp');

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
    const [showInactiveResend, setShowInactiveResend] = useState(false);
    const [showInactiveSmtp, setShowInactiveSmtp] = useState(false);
    const [clearApiKey, setClearApiKey] = useState(false);
    const [clearSmtpPassword, setClearSmtpPassword] = useState(false);

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
          replyTo !== (settings.resend.reply_to || '') ||
          apiKey.trim().length > 0 ||
          clearApiKey
        : false;

    const normalizedSettingsPort = (settings?.smtp.port || '').toString();
    const hasSmtpChanges = settings
        ? smtpHost !== (settings.smtp.host || '') ||
          smtpPort !== normalizedSettingsPort ||
          smtpUsername !== (settings.smtp.username || '') ||
          smtpEncryption !== (settings.smtp.encryption || '') ||
          smtpFromEmail !== (settings.smtp.from_email || '') ||
          smtpFromName !== (settings.smtp.from_name || '') ||
          smtpReplyTo !== (settings.smtp.reply_to || '') ||
          smtpPassword.trim().length > 0 ||
          clearSmtpPassword
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

    const handleApiKeyChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const value = e.target.value;
        setApiKey(value);
        if (value.trim().length > 0) {
            setClearApiKey(false);
        }
    };

    const handleSaveResend = () => {
        clearFlashes();
        setStatus('processing');

        const payload: EmailSettingsUpdate = {
            from_email: fromEmail,
            from_name: fromName,
            reply_to: replyTo,
        };

        if (apiKey.trim().length > 0 || clearApiKey) {
            payload.api_key = clearApiKey ? '' : apiKey.trim();
            setSavingApiKey(true);
        }

        updateSettings(payload)
            .then((updatedSettings) => {
                setStatus('success');
                setSettings(updatedSettings);
                setFromEmail(updatedSettings.resend.from_email || '');
                setFromName(updatedSettings.resend.from_name || '');
                setReplyTo(updatedSettings.resend.reply_to || '');
                setApiKey('');
                setClearApiKey(false);
                setSavingApiKey(false);

                addFlash({
                    key: 'email:settings:resend',
                    type: 'success',
                    message: 'Resend settings saved successfully',
                });
            })
            .catch((error) => {
                setStatus('error');
                setSavingApiKey(false);
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

        if (clearSmtpPassword) {
            payload.smtp_password = '';
        } else if (smtpPassword.trim()) {
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
                setClearSmtpPassword(false);

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
        if (value === transport) {
            return;
        }

        const previous = transport;
        setSavingTransport(true);
        setTransport(value);
        clearFlashes();

        updateSettings({ transport: value })
            .then((updated) => {
                setSavingTransport(false);
                setSettings(updated);
                setTransport(updated.transport);

                addFlash({
                    key: 'email:settings:transport',
                    type: 'success',
                    message: `Active transport switched to ${getTransportLabel(updated.transport)}`,
                });
            })
            .catch((error) => {
                setSavingTransport(false);
                setTransport(previous);
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
    const transportLabel = getTransportLabel(transport);

    return (
        <AdminBox title={'Email Settings'} icon={faEnvelope} status={status}>
            <div className={'flex flex-col gap-6'}>
                <StatusBanner
                    enabled={enabled}
                    transport={transportLabel}
                    onToggle={toggleEnabled}
                    saving={savingEnabled}
                    secondary={secondary}
                    toggleHint={toggleHint}
                />

                <Card sectionBg={secondary}>
                    <div className={'space-y-3'}>
                        <div className={'space-y-1'}>
                            <Label>Delivery Method</Label>
                            <p className={'text-sm text-gray-400'}>Choose which transport is active.</p>
                        </div>
                        <div className={'grid grid-cols-1 gap-3 sm:grid-cols-2'}>
                            <TransportChoice
                                label={'Resend'}
                                description={'Use Resend API for delivery'}
                                active={isResendActive}
                                onSelect={() => handleTransportChange('resend')}
                                disabled={savingTransport}
                            />
                            <TransportChoice
                                label={'SMTP'}
                                description={'Use your SMTP server'}
                                active={isSmtpActive}
                                onSelect={() => handleTransportChange('smtp')}
                                disabled={savingTransport}
                            />
                        </div>
                    </div>
                </Card>

                {/* Active transport (primary focus) */}
                {isResendActive ? renderResendForm(true) : renderSmtpForm(true)}

                {/* Collapsed inactive transport */}
                {!isResendActive && renderResendForm(false, showInactiveResend, () => setShowInactiveResend((v) => !v))}
                {!isSmtpActive && renderSmtpForm(false, showInactiveSmtp, () => setShowInactiveSmtp((v) => !v))}

                <Card sectionBg={secondary}>
                    <div className={'flex flex-col gap-2'}>
                        <div className={'flex items-center justify-between'}>
                            <div className={'space-y-1'}>
                                <h3 className={'text-lg font-semibold text-white'}>Send a Test Email</h3>
                                <p className={'text-sm text-gray-400'}>
                                    After configuring the active transport, send yourself a test to confirm delivery.
                                </p>
                            </div>
                        </div>
                        <SendTestEmail />
                    </div>
                </Card>
            </div>
        </AdminBox>
    );

    function renderResendForm(active: boolean, expanded?: boolean, toggleExpand?: () => void) {
        const content = (
            <div className={'space-y-5'}>
                <Card sectionBg={secondary}>
                    <div className={'flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between'}>
                        <div className={'space-y-1'}>
                            <Label>API Key</Label>
                            <p className={'text-sm text-gray-400'}>
                                Enter a key then click “Save Resend Settings”. Check “Clear saved key” to remove it.
                            </p>
                        </div>
                        <div className={'flex items-center gap-2'}>
                            {settings?.resend.api_key && (
                                <span className={'inline-flex items-center gap-1 text-sm text-green-400'}>
                                    <FontAwesomeIcon icon={faCheckCircle} />
                                    Key saved
                                </span>
                            )}
                            {savingApiKey && <FontAwesomeIcon icon={faSpinner} className={'fa-spin text-blue-500'} />}
                        </div>
                    </div>
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
                    <div className={'mt-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between'}>
                        <p className={'text-sm text-gray-400'}>
                            Get your key at{' '}
                            <a
                                href="https://resend.com/api-keys"
                                target="_blank"
                                rel="noopener noreferrer"
                                className={'text-blue-400 hover:text-blue-300'}
                            >
                                resend.com/api-keys
                            </a>
                        </p>
                        <label className={'flex items-center gap-2 text-sm text-gray-300'}>
                            <input
                                type='checkbox'
                                checked={clearApiKey}
                                onChange={(e) => setClearApiKey(e.target.checked)}
                                disabled={!enabled}
                            />
                            Clear saved key
                        </label>
                    </div>
                </Card>

                <Card sectionBg={secondary}>
                    <SectionHeader
                        title={'Sender details'}
                        description={'Who the email appears to come from.'}
                    />
                    <div className={'grid grid-cols-1 gap-4 sm:grid-cols-2'}>
                        <div className={'space-y-2'}>
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
                        </div>
                        <div className={'space-y-2'}>
                            <Label>From Name</Label>
                            <Input
                                value={fromName}
                                onChange={(e) => setFromName(e.target.value)}
                                placeholder={'Your App Name'}
                                disabled={!enabled}
                            />
                        </div>
                        <div className={'space-y-2'}>
                            <Label>Reply-To Email</Label>
                            <Input
                                type={'email'}
                                value={replyTo}
                                onChange={(e) => setReplyTo(e.target.value)}
                                placeholder={'support@yourdomain.com'}
                                disabled={!enabled}
                            />
                        </div>
                    </div>
                    <div className={'mt-2 space-y-1 rounded-md border border-amber-500/40 bg-amber-900/30 p-3 text-sm'}>
                        <p className={'text-amber-100'}>
                            <strong className={'text-amber-200'}>Domain verification:</strong> verify this domain in Resend or sends will fail.
                        </p>
                        <p className={'text-amber-100'}>
                            <strong className={'text-amber-200'}>Tip:</strong> use a monitored inbox (e.g. support@) to reduce spam filtering.
                        </p>
                    </div>
                </Card>

                <div className={'flex items-center gap-3'}>
                    <Button onClick={handleSaveResend} disabled={!hasResendChanges || status === 'processing'}>
                        Save Resend Settings
                    </Button>
                    {!hasResendChanges && <p className={'text-sm text-gray-400'}>No changes to save</p>}
                </div>
            </div>
        );

        if (active) {
            return (
                <TransportSection
                    title={'Resend'}
                    active
                    secondary={secondary}
                    badgeText={'Active'}
                >
                    {content}
                </TransportSection>
            );
        }

        return (
            <TransportSection
                title={'Resend'}
                active={false}
                secondary={secondary}
                badgeText={'Inactive'}
                collapsed
                expanded={expanded}
                onToggle={toggleExpand}
                summary={`From: ${settings?.resend.from_email || 'Not set'}`}
            >
                {expanded ? content : null}
            </TransportSection>
        );
    }

    function renderSmtpForm(active: boolean, expanded?: boolean, toggleExpand?: () => void) {
        const content = (
            <div className={'space-y-5'}>
                <Card sectionBg={secondary}>
                    <SectionHeader
                        title={'Server connection'}
                        description={'SMTP host and authentication details.'}
                    />
                    <div className={'grid grid-cols-1 gap-4 sm:grid-cols-2'}>
                        <div className={'space-y-2'}>
                            <Label>Host</Label>
                            <Input
                                value={smtpHost}
                                onChange={(e) => setSmtpHost(e.target.value)}
                                placeholder={'smtp.yourdomain.com'}
                                disabled={!enabled}
                            />
                        </div>
                        <div className={'space-y-2'}>
                            <Label>Port</Label>
                            <Input
                                type={'number'}
                                value={smtpPort}
                                onChange={(e) => setSmtpPort(e.target.value)}
                                placeholder={'587'}
                                disabled={!enabled}
                            />
                        </div>
                        <div className={'space-y-2'}>
                            <Label>Username</Label>
                            <Input
                                value={smtpUsername}
                                onChange={(e) => setSmtpUsername(e.target.value)}
                                placeholder={'SMTP username'}
                                disabled={!enabled}
                            />
                        </div>
                        <div className={'space-y-2'}>
                            <Label>Password</Label>
                            <Input
                                type={'password'}
                                value={smtpPassword}
                                onChange={(e) => setSmtpPassword(e.target.value)}
                                placeholder={
                                    settings?.smtp.password_set
                                        ? '\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022 (configured)'
                                        : 'SMTP password'
                                }
                                disabled={!enabled}
                            />
                            <div className={'flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between'}>
                                <p className={'text-xs text-gray-400'}>
                                    Leave blank to keep the existing password, or check “Clear password”.
                                </p>
                                <label className={'flex items-center gap-2 text-xs text-gray-300'}>
                                    <input
                                        type='checkbox'
                                        checked={clearSmtpPassword}
                                        onChange={(e) => setClearSmtpPassword(e.target.checked)}
                                        disabled={!enabled}
                                    />
                                    Clear password
                                </label>
                            </div>
                        </div>
                        <div className={'space-y-2'}>
                            <Label>Encryption</Label>
                            <select
                                className={
                                    'w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-white'
                                }
                                value={smtpEncryption}
                                onChange={(e) => setSmtpEncryption(e.target.value)}
                                disabled={!enabled}
                            >
                                <option value="">None</option>
                                <option value="tls">TLS</option>
                                <option value="ssl">SSL</option>
                            </select>
                        </div>
                    </div>
                </Card>

                <Card sectionBg={secondary}>
                    <SectionHeader
                        title={'Sender details'}
                        description={'From/reply-to information for SMTP emails.'}
                    />
                    <div className={'grid grid-cols-1 gap-4 sm:grid-cols-2'}>
                        <div className={'space-y-2'}>
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
                        <div className={'space-y-2'}>
                            <Label>From Name</Label>
                            <Input
                                value={smtpFromName}
                                onChange={(e) => setSmtpFromName(e.target.value)}
                                placeholder={'Your App Name'}
                                disabled={!enabled}
                            />
                        </div>
                        <div className={'space-y-2'}>
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
                </Card>

                <div className={'flex items-center gap-3'}>
                    <Button onClick={handleSaveSmtp} disabled={!hasSmtpChanges || status === 'processing'}>
                        Save SMTP Settings
                    </Button>
                    {!hasSmtpChanges && <p className={'text-sm text-gray-400'}>No changes to save</p>}
                </div>
            </div>
        );

        if (active) {
            return (
                <TransportSection
                    title={'SMTP'}
                    active
                    secondary={secondary}
                    badgeText={'Active'}
                >
                    {content}
                </TransportSection>
            );
        }

        return (
            <TransportSection
                title={'SMTP'}
                active={false}
                secondary={secondary}
                badgeText={'Inactive'}
                collapsed
                expanded={expanded}
                onToggle={toggleExpand}
                summary={`Host: ${settings?.smtp.host || 'Not set'}`}
            >
                {expanded ? content : null}
            </TransportSection>
        );
    }
};

const TransportSection = ({
    title,
    active,
    children,
    secondary,
    badgeText,
    collapsed = false,
    expanded = false,
    onToggle,
    summary,
}: {
    title: string;
    active: boolean;
    children: ReactNode;
    secondary: string;
    badgeText: string;
    collapsed?: boolean;
    expanded?: boolean;
    onToggle?: () => void;
    summary?: string;
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
                {!active && (
                    <div className={'flex items-center gap-3 text-xs text-gray-400'}>
                        <span>Inactive transport</span>
                        {summary && <span className={'text-gray-500'}>{summary}</span>}
                        {onToggle && (
                            <Button.Dark onClick={onToggle} size={Button.Sizes.Small}>
                                {expanded ? 'Hide settings' : 'View settings'}
                            </Button.Dark>
                        )}
            </div>
                )}
            </div>
            {!collapsed || expanded ? <div className={'space-y-4'}>{children}</div> : null}
        </div>
    );
};

const Card = ({ children, sectionBg }: { children: ReactNode; sectionBg: string }) => (
    <div className={'rounded-lg border border-neutral-700 p-4'} style={{ backgroundColor: sectionBg }}>
        {children}
    </div>
);

const SectionHeader = ({ title, description }: { title: string; description: string }) => (
    <div className={'space-y-1'}>
        <h3 className={'text-lg font-semibold text-white'}>{title}</h3>
        <p className={'text-sm text-gray-400'}>{description}</p>
    </div>
);

const getTransportLabel = (value: EmailTransport): string => (value === 'smtp' ? 'SMTP' : 'Resend');

const StatusBanner = ({
    enabled,
    transport,
    onToggle,
    saving,
    secondary,
    toggleHint,
}: {
    enabled: boolean;
    transport: string;
    onToggle: () => void;
    saving: boolean;
    secondary: string;
    toggleHint: string;
}) => {
    const isOn = enabled;
    const StatusToggleButton = isOn ? Button.Success : Button.Danger;
    const statusText = isOn ? 'Enabled' : 'Disabled';
    const message = isOn
        ? `Emails are being sent via ${transport}.`
        : 'Email delivery is currently disabled.';

    return (
        <div
            className={`rounded-lg border p-4 shadow-sm ${
                isOn ? 'border-green-600/60 bg-green-950/60' : 'border-red-600/60 bg-red-950/60'
            }`}
            style={{ backgroundColor: secondary }}
        >
            <div className={'flex flex-col gap-3 md:flex-row md:items-center md:justify-between'}>
                <div className={'space-y-1'}>
                    <div className={'flex items-center gap-2'}>
                        <span
                            className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold ${
                                isOn ? 'bg-green-900 text-green-100' : 'bg-red-900 text-red-100'
                            }`}
                        >
                            <span className={`h-2 w-2 rounded-full ${isOn ? 'bg-green-400' : 'bg-red-400'}`} />
                            Email delivery {statusText}
                        </span>
                        <span className={'text-sm text-gray-300'}>Active transport: {transport}</span>
                    </div>
                    <p className={'text-sm text-gray-200'}>{message}</p>
                </div>
                <div className={'flex items-center gap-2'}>
                    <StatusToggleButton onClick={onToggle} disabled={saving} loading={saving}>
                        {isOn ? 'Disable' : 'Enable'}
                    </StatusToggleButton>
                </div>
            </div>
            <p className={'mt-2 text-xs text-gray-400'}>{toggleHint}</p>
        </div>
    );
};

const TransportChoice = ({
    label,
    description,
    active,
    onSelect,
    disabled,
}: {
    label: string;
    description: string;
    active: boolean;
    onSelect: () => void;
    disabled: boolean;
}) => {
    return (
        <button
            type={'button'}
            onClick={onSelect}
            disabled={disabled}
            className={`flex w-full flex-col items-start gap-2 rounded-lg border p-4 text-left transition ${
                active
                    ? 'border-blue-500/70 bg-blue-950/40 shadow-md'
                    : 'border-neutral-700 bg-neutral-900/60 hover:border-neutral-600'
            } ${disabled ? 'opacity-70' : ''}`}
        >
            <div className={'flex items-center gap-2'}>
                <div
                    className={`h-3 w-3 rounded-full ${active ? 'bg-blue-400' : 'bg-neutral-600'}`}
                    aria-hidden
                />
                <span className={'text-sm font-semibold text-white'}>{label}</span>
                {active && (
                    <span className={'rounded-full bg-blue-900 px-2 py-0.5 text-xs font-semibold text-blue-100'}>
                        Active
                    </span>
                )}
            </div>
            <p className={'text-xs text-gray-400'}>{description}</p>
        </button>
    );
};
