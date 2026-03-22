import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faEnvelope, faPaperPlane, faPlug, faServer, faVial } from '@fortawesome/free-solid-svg-icons';
import type { IconProp } from '@fortawesome/fontawesome-svg-core';
import AdminBox from '@/elements/AdminBox';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import { Button } from '@/elements/button';
import Pill from '@/elements/Pill';
import useFlash from '@/plugins/useFlash';
import useStatus from '@/plugins/useStatus';
import { useStoreState } from '@/state/hooks';
import {
    EmailSettings,
    EmailSettingsUpdate,
    EmailTransport,
    getSettings,
    sendTestEmail,
    testResendConnection,
    testSmtpConnection,
    updateSettings,
} from '@/api/routes/admin/email';

type TabKey = 'overview' | 'smtp' | 'resend' | 'testing';

type TestResult = {
    status: 'success' | 'error';
    provider: EmailTransport;
    message: string;
    tested_at: string;
    code?: string;
};

export default () => {
    const { status, setStatus } = useStatus();
    const { clearFlashes, addFlash, clearAndAddHttpError } = useFlash();
    const { primary, secondary, headers } = useStoreState((state) => state.theme.data!.colors);

    const [activeTab, setActiveTab] = useState<TabKey>('overview');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [testingProvider, setTestingProvider] = useState<EmailTransport | null>(null);
    const [testingSend, setTestingSend] = useState(false);

    const [settings, setSettings] = useState<EmailSettings | null>(null);
    const [enabled, setEnabled] = useState(false);
    const [transport, setTransport] = useState<EmailTransport>('smtp');
    const [globalSender, setGlobalSender] = useState({ fromEmail: '', fromName: '', replyTo: '' });

    const [resendConfig, setResendConfig] = useState({ from_email: '', from_name: '', reply_to: '' });
    const [resendApiKeyInput, setResendApiKeyInput] = useState('');
    const [resendKeySet, setResendKeySet] = useState(false);

    const [smtpConfig, setSmtpConfig] = useState({
        host: '',
        port: '',
        username: '',
        encryption: '',
        from_email: '',
        from_name: '',
        reply_to: '',
    });
    const [smtpPasswordInput, setSmtpPasswordInput] = useState('');
    const [smtpPasswordSet, setSmtpPasswordSet] = useState(false);

    const [testResults, setTestResults] = useState<{
        smtp?: TestResult;
        resend?: TestResult;
        email?: TestResult;
    }>({});
    const [testRecipient, setTestRecipient] = useState('');

    const pickWithFallback = (
        primary?: string,
        smtpValue?: string,
        resendValue?: string,
        defaultValue = ''
    ) => primary || smtpValue || resendValue || defaultValue;

    useEffect(() => {
        setLoading(true);
        getSettings()
            .then((data) => {
                setSettings(data);
                setEnabled(data.enabled);
                setTransport(data.transport);
                setResendKeySet(data.resend.api_key);
                setSmtpPasswordSet(data.smtp.password_set);

                const derivedFromEmail = pickWithFallback(
                    data.transport === 'smtp' ? data.smtp.from_email : data.resend.from_email,
                    data.smtp.from_email,
                    data.resend.from_email
                );
                const derivedFromName = pickWithFallback(
                    data.transport === 'smtp' ? data.smtp.from_name : data.resend.from_name,
                    data.smtp.from_name,
                    data.resend.from_name
                );
                const derivedReplyTo =
                    data.transport === 'smtp'
                        ? pickWithFallback(data.smtp.reply_to, data.resend.reply_to, undefined, derivedFromEmail)
                        : pickWithFallback(data.resend.reply_to, data.smtp.reply_to, undefined, derivedFromEmail);

                setGlobalSender({
                    fromEmail: derivedFromEmail,
                    fromName: derivedFromName,
                    replyTo: derivedReplyTo,
                });

                setResendConfig({
                    from_email: data.resend.from_email || derivedFromEmail,
                    from_name: data.resend.from_name || derivedFromName,
                    reply_to: data.resend.reply_to || derivedReplyTo,
                });

                setSmtpConfig({
                    host: data.smtp.host || '',
                    port: (data.smtp.port || '').toString(),
                    username: data.smtp.username || '',
                    encryption: data.smtp.encryption || '',
                    from_email: data.smtp.from_email || derivedFromEmail,
                    from_name: data.smtp.from_name || derivedFromName,
                    reply_to: data.smtp.reply_to || derivedReplyTo,
                });
            })
            .catch((error) => clearAndAddHttpError({ key: 'email:settings:load', error }))
            .finally(() => setLoading(false));
    }, []);

    const resolvedReplyTo = useMemo(
        () => globalSender.replyTo || globalSender.fromEmail,
        [globalSender.replyTo, globalSender.fromEmail]
    );

    useEffect(() => {
        setSmtpConfig((prev) => ({
            ...prev,
            from_email: globalSender.fromEmail,
            from_name: globalSender.fromName,
            reply_to: resolvedReplyTo,
        }));
        setResendConfig((prev) => ({
            ...prev,
            from_email: globalSender.fromEmail,
            from_name: globalSender.fromName,
            reply_to: resolvedReplyTo,
        }));
    }, [globalSender.fromEmail, globalSender.fromName, resolvedReplyTo]);

    const initialSender = useMemo(() => {
        if (!settings) {
            return { fromEmail: '', fromName: '', replyTo: '' };
        }

        const active = settings.transport === 'smtp' ? settings.smtp : settings.resend;

        return {
            fromEmail: pickWithFallback(active.from_email, settings.smtp.from_email, settings.resend.from_email),
            fromName: pickWithFallback(active.from_name, settings.smtp.from_name, settings.resend.from_name),
            replyTo: pickWithFallback(active.reply_to, settings.smtp.reply_to, settings.resend.reply_to),
        };
    }, [settings]);

    const hasChanges = useMemo(() => {
        if (!settings) return false;

        const smtpPort = (settings.smtp.port || '').toString();

        return (
            enabled !== settings.enabled ||
            transport !== settings.transport ||
            globalSender.fromEmail !== initialSender.fromEmail ||
            globalSender.fromName !== initialSender.fromName ||
            globalSender.replyTo !== initialSender.replyTo ||
            smtpConfig.host !== (settings.smtp.host || '') ||
            smtpConfig.port !== smtpPort ||
            smtpConfig.username !== (settings.smtp.username || '') ||
            smtpConfig.encryption !== (settings.smtp.encryption || '') ||
            resendApiKeyInput.trim().length > 0 ||
            smtpPasswordInput.trim().length > 0
        );
    }, [
        enabled,
        transport,
        globalSender.fromEmail,
        globalSender.fromName,
        globalSender.replyTo,
        smtpConfig.host,
        smtpConfig.port,
        smtpConfig.username,
        smtpConfig.encryption,
        resendApiKeyInput,
        smtpPasswordInput,
        settings,
        initialSender.fromEmail,
        initialSender.fromName,
        initialSender.replyTo,
    ]);

    const smtpConfigured = useMemo(() => {
        const hasPassword = smtpPasswordSet || smtpPasswordInput.trim().length > 0 || !smtpConfig.username;

        return Boolean(smtpConfig.host && smtpConfig.port && hasPassword && globalSender.fromEmail);
    }, [smtpConfig, smtpPasswordInput, smtpPasswordSet, globalSender.fromEmail]);

    const resendConfigured = useMemo(() => {
        return Boolean((resendKeySet || resendApiKeyInput.trim().length > 0) && globalSender.fromEmail);
    }, [resendApiKeyInput, resendKeySet, globalSender.fromEmail]);

    const currentStatus = useMemo(() => {
        if (!enabled) return 'Email delivery is disabled';
        if (transport === 'smtp' && !smtpConfigured) return 'SMTP configuration incomplete';
        if (transport === 'resend' && !resendConfigured) return 'Resend configuration incomplete';

        if (testResults.email?.status === 'error') {
            return 'Test failed — see details below';
        }

        return 'Ready';
    }, [enabled, transport, smtpConfigured, resendConfigured, testResults.email]);

    const lastSuccess =
        (testResults.email?.status === 'success' && testResults.email) ||
        (testResults[transport]?.status === 'success' ? testResults[transport] : undefined);

    const handleSave = () => {
        if (!settings) return;
        setSaving(true);
        clearFlashes('email:settings');
        setStatus('processing');

        const payload: EmailSettingsUpdate = {
            enabled,
            transport,
            from_email: globalSender.fromEmail,
            from_name: globalSender.fromName,
            reply_to: globalSender.replyTo,
            smtp_host: smtpConfig.host,
            smtp_port: smtpConfig.port,
            smtp_username: smtpConfig.username,
            smtp_encryption: smtpConfig.encryption,
            smtp_from_email: globalSender.fromEmail,
            smtp_from_name: globalSender.fromName,
            smtp_reply_to: globalSender.replyTo,
        };

        if (resendApiKeyInput.trim()) {
            payload.api_key = resendApiKeyInput.trim();
        }

        if (smtpPasswordInput.trim()) {
            payload.smtp_password = smtpPasswordInput.trim();
        }

        updateSettings(payload)
            .then((updated) => {
                setSettings(updated);
                setEnabled(updated.enabled);
                setTransport(updated.transport);
                setResendKeySet(updated.resend.api_key);
                setSmtpPasswordSet(updated.smtp.password_set);
                setResendApiKeyInput('');
                setSmtpPasswordInput('');
                setGlobalSender({
                    fromEmail: updated.resend.from_email || updated.smtp.from_email || globalSender.fromEmail,
                    fromName: updated.resend.from_name || updated.smtp.from_name || globalSender.fromName,
                    replyTo: updated.resend.reply_to || updated.smtp.reply_to || globalSender.replyTo,
                });

                setSmtpConfig((prev) => ({
                    ...prev,
                    host: updated.smtp.host || '',
                    port: (updated.smtp.port || '').toString(),
                    username: updated.smtp.username || '',
                    encryption: updated.smtp.encryption || '',
                }));

                addFlash({
                    key: 'email:settings',
                    type: 'success',
                    message: 'Email settings saved successfully.',
                });
                setStatus('success');
            })
            .catch((error) => {
                setStatus('error');
                clearAndAddHttpError({ key: 'email:settings', error });
            })
            .finally(() => setSaving(false));
    };

    const handleTransportChange = (value: EmailTransport) => {
        setTransport(value);
    };

    const handleTestProvider = (provider: EmailTransport) => {
        setTestingProvider(provider);
        clearFlashes('email:settings:test');
        const tester = provider === 'smtp' ? testSmtpConnection : testResendConnection;

        tester()
            .then((response) => {
                const message =
                    response.success && response.tested_at
                        ? `Connection successful (${formatDate(response.tested_at)})`
                        : response.success
                          ? 'Connection successful'
                          : extractErrorMessage(response.error, 'Connection failed');

                const code = !response.success && response.error && typeof response.error !== 'string' ? response.error.code : undefined;

                setTestResults((prev) => ({
                    ...prev,
                    [provider]: {
                        status: response.success ? 'success' : 'error',
                        provider,
                        message,
                        tested_at: response.tested_at || new Date().toISOString(),
                        code,
                    },
                }));

                addFlash({
                    key: 'email:settings:test',
                    type: response.success ? 'success' : 'danger',
                    message,
                });
            })
            .catch((error) => clearAndAddHttpError({ key: 'email:settings:test', error }))
            .finally(() => setTestingProvider(null));
    };

    const handleSendTestEmail = () => {
        if (!testRecipient) {
            addFlash({
                key: 'email:settings:test',
                type: 'danger',
                message: 'Enter a recipient email first.',
            });
            return;
        }

        setTestingSend(true);
        clearFlashes('email:settings:test');

        sendTestEmail({ to: testRecipient })
            .then((response) => {
                const success = response.success;
                const provider = response.provider || transport;

                const message = success
                    ? `Test email sent via ${provider.toUpperCase()}`
                    : extractErrorMessage(response.error, 'Failed to send test email');

                setTestResults((prev) => ({
                    ...prev,
                    email: {
                        status: success ? 'success' : 'error',
                        provider,
                        message,
                        tested_at: response.tested_at || new Date().toISOString(),
                        code: !success && response.error && typeof response.error !== 'string' ? response.error.code : undefined,
                    },
                }));

                addFlash({
                    key: 'email:settings:test',
                    type: success ? 'success' : 'danger',
                    message,
                });
            })
            .catch((error) => clearAndAddHttpError({ key: 'email:settings:test', error }))
            .finally(() => setTestingSend(false));
    };

    if (loading || !settings) {
        return (
            <AdminBox title={'Email Settings'} icon={faEnvelope}>
                <div className={'flex items-center justify-center py-8'}>
                    <FontAwesomeIcon icon={faVial} className={'fa-spin text-gray-400'} size={'2x'} />
                </div>
            </AdminBox>
        );
    }

    return (
        <AdminBox title={'Email Settings'} icon={faEnvelope} status={status} byKey={'email:settings'}>
            <div className={'flex flex-col gap-5'}>
                <TabList active={activeTab} onSelect={setActiveTab} primary={primary} />

                {activeTab === 'overview' && (
                    <div className={'space-y-4'}>
                        <div className={'grid grid-cols-1 gap-4 lg:grid-cols-3'}>
                            <Card className={'h-full'}>
                                <div className={'flex h-full flex-col justify-between gap-3'}>
                                    <div className={'flex items-center justify-between'}>
                                        <div className={'space-y-1'}>
                                            <Label>Email system</Label>
                                            <p className={'text-sm text-gray-400'}>Toggle delivery for all providers.</p>
                                        </div>
                                        <div className={'flex items-center gap-2'}>
                                            <StatusBadge status={enabled ? 'success' : 'warning'} />
                                            {(enabled ? Button.Danger : Button.Success)({
                                                children: enabled ? 'Disable' : 'Enable',
                                                onClick: () => setEnabled((v) => !v),
                                                disabled: saving,
                                            })}
                                        </div>
                                    </div>
                                    <p className={'text-xs text-gray-500'}>Changes apply when you save.</p>
                                </div>
                            </Card>

                            <Card className={'h-full'}>
                                <div className={'flex h-full flex-col justify-between gap-3'}>
                                    <div className={'space-y-2'}>
                                        <Label>Active provider</Label>
                                        <div className={'grid grid-cols-2 gap-2'}>
                                            <ProviderPill
                                                label={'SMTP'}
                                                active={transport === 'smtp'}
                                                onClick={() => handleTransportChange('smtp')}
                                                primary={primary}
                                            />
                                            <ProviderPill
                                                label={'Resend'}
                                                active={transport === 'resend'}
                                                onClick={() => handleTransportChange('resend')}
                                                primary={primary}
                                            />
                                        </div>
                                    </div>
                                    <p className={'text-xs text-gray-500'}>
                                        Switching providers preserves your saved configuration.
                                    </p>
                                </div>
                            </Card>

                            <Card className={'h-full'}>
                                <div className={'flex h-full flex-col justify-between gap-3'}>
                                    <div className={'flex items-start justify-between'}>
                                        <div>
                                            <Label>Current status</Label>
                                            <p className={'text-sm text-gray-300'}>{currentStatus}</p>
                                        </div>
                                        <StatusBadge status={currentStatus === 'Ready' ? 'success' : 'warning'} />
                                    </div>
                                    <div className={'text-xs text-gray-500'}>
                                        {lastSuccess
                                            ? `Last successful test: ${new Date(lastSuccess.tested_at).toLocaleString()}`
                                            : 'No successful tests yet.'}
                                    </div>
                                </div>
                            </Card>
                        </div>

                        <Card>
                            <div className={'space-y-3'}>
                                <Label>Global sender identity</Label>
                                <div className={'grid grid-cols-1 gap-4 sm:grid-cols-2'}>
                                    <div className={'space-y-1'}>
                                        <Label>From Name</Label>
                                        <Input
                                            value={globalSender.fromName}
                                            onChange={(e) =>
                                                setGlobalSender((prev) => ({ ...prev, fromName: e.target.value }))
                                            }
                                            placeholder={'Your App'}
                                        />
                                    </div>
                                    <div className={'space-y-1'}>
                                        <Label>
                                            From Email <span className={'text-red-500'}>*</span>
                                        </Label>
                                        <Input
                                            type={'email'}
                                            value={globalSender.fromEmail}
                                            onChange={(e) =>
                                                setGlobalSender((prev) => ({ ...prev, fromEmail: e.target.value }))
                                            }
                                            placeholder={'noreply@yourdomain.com'}
                                        />
                                    </div>
                                    <div className={'space-y-1'}>
                                        <Label>Reply-To Email</Label>
                                        <Input
                                            type={'email'}
                                            value={globalSender.replyTo}
                                            onChange={(e) =>
                                                setGlobalSender((prev) => ({ ...prev, replyTo: e.target.value }))
                                            }
                                            placeholder={'support@yourdomain.com'}
                                        />
                                    </div>
                                </div>
                            </div>
                        </Card>

                        <div className={'grid grid-cols-1 gap-4 lg:grid-cols-2'}>
                            <StatusCard
                                title={'SMTP'}
                                active={transport === 'smtp'}
                                configured={smtpConfigured}
                                lastTest={testResults.smtp}
                                onTest={() => handleTestProvider('smtp')}
                                testing={testingProvider === 'smtp'}
                            />
                            <StatusCard
                                title={'Resend'}
                                active={transport === 'resend'}
                                configured={resendConfigured}
                                lastTest={testResults.resend}
                                onTest={() => handleTestProvider('resend')}
                                testing={testingProvider === 'resend'}
                            />
                        </div>
                    </div>
                )}

                {activeTab === 'smtp' && (
                    <div className={'space-y-4'}>
                        <Card>
                            <div className={'flex items-center justify-between'}>
                                <div className={'space-y-1'}>
                                    <Label>SMTP connection</Label>
                                    <p className={'text-sm text-gray-400'}>Server and authentication settings.</p>
                                </div>
                                <span
                                    className={`rounded-full px-3 py-1 text-xs font-semibold ${
                                        transport === 'smtp' ? 'bg-green-900 text-green-100' : 'bg-neutral-800 text-gray-300'
                                    }`}
                                >
                                    {transport === 'smtp' ? 'Active provider' : 'Inactive'}
                                </span>
                            </div>
                            <div className={`mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 ${transport !== 'smtp' ? 'opacity-70' : ''}`}>
                                <InputField
                                    label={'Host'}
                                    value={smtpConfig.host}
                                    onChange={(value) => setSmtpConfig((prev) => ({ ...prev, host: value }))}
                                    placeholder={'smtp.yourdomain.com'}
                                />
                                <InputField
                                    label={'Port'}
                                    value={smtpConfig.port}
                                    onChange={(value) => setSmtpConfig((prev) => ({ ...prev, port: value }))}
                                    placeholder={'587'}
                                    type={'number'}
                                />
                                <InputField
                                    label={'Username'}
                                    value={smtpConfig.username}
                                    onChange={(value) => setSmtpConfig((prev) => ({ ...prev, username: value }))}
                                    placeholder={'user@yourdomain.com'}
                                />
                                <div className={'space-y-1'}>
                                    <Label>Password</Label>
                                    <Input
                                        type={'password'}
                                        value={smtpPasswordInput}
                                        onChange={(e) => setSmtpPasswordInput(e.target.value)}
                                        placeholder={
                                            smtpPasswordSet ? '•••••••• (saved)' : 'Enter SMTP password'
                                        }
                                    />
                                    <p className={'text-xs text-gray-500'}>
                                        Leave blank to keep the existing password.
                                    </p>
                                </div>
                                <div className={'space-y-1'}>
                                    <Label>Encryption</Label>
                                    <select
                                        className={
                                            'w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-white'
                                        }
                                        value={smtpConfig.encryption}
                                        onChange={(e) => setSmtpConfig((prev) => ({ ...prev, encryption: e.target.value }))}
                                    >
                                        <option value="">None</option>
                                        <option value="tls">TLS</option>
                                        <option value="ssl">SSL</option>
                                    </select>
                                </div>
                            </div>
                        </Card>

                        <Card>
                            <div className={'flex items-center justify-between'}>
                                <div className={'space-y-1'}>
                                    <Label>Status</Label>
                                    <p className={'text-sm text-gray-300'}>
                                        {smtpConfigured ? 'Configured' : 'Missing required fields'}
                                    </p>
                                    <p className={'text-xs text-gray-500'}>
                                        Password set: {smtpPasswordSet ? 'Yes' : 'No'}
                                    </p>
                                </div>
                                <Button onClick={() => handleTestProvider('smtp')} loading={testingProvider === 'smtp'} size={Button.Sizes.Small}>
                                    <FontAwesomeIcon icon={faVial} className={'mr-1'} />
                                    Test SMTP Connection
                                </Button>
                            </div>
                            {testResults.smtp && (
                                <ResultBanner result={testResults.smtp} />
                            )}
                        </Card>
                    </div>
                )}

                {activeTab === 'resend' && (
                    <div className={'space-y-4'}>
                        <Card>
                            <div className={'flex items-center justify-between'}>
                                <div className={'space-y-1'}>
                                    <Label>Resend configuration</Label>
                                    <p className={'text-sm text-gray-400'}>API access.</p>
                                </div>
                                <span
                                    className={`rounded-full px-3 py-1 text-xs font-semibold ${
                                        transport === 'resend' ? 'bg-green-900 text-green-100' : 'bg-neutral-800 text-gray-300'
                                    }`}
                                >
                                    {transport === 'resend' ? 'Active provider' : 'Inactive'}
                                </span>
                            </div>

                            <div className={`mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 ${transport !== 'resend' ? 'opacity-70' : ''}`}>
                                <div className={'space-y-1'}>
                                    <Label>API Key</Label>
                                    <Input
                                        type={'password'}
                                        value={resendApiKeyInput}
                                        onChange={(e) => setResendApiKeyInput(e.target.value)}
                                        placeholder={
                                            resendKeySet
                                                ? 'Key saved — enter to replace'
                                                : 'Enter Resend API key'
                                        }
                                    />
                                    <p className={'text-xs text-gray-500'}>
                                        Keys are stored securely. Leave blank to keep the existing key.
                                    </p>
                                </div>
                            </div>
                        </Card>

                        <Card>
                            <div className={'flex items-center justify-between'}>
                                <div className={'space-y-1'}>
                                    <Label>Status</Label>
                                    <p className={'text-sm text-gray-300'}>
                                        {resendConfigured ? 'Configured' : 'Missing required fields'}
                                    </p>
                                    <p className={'text-xs text-gray-500'}>
                                        API key set: {resendKeySet ? 'Yes' : 'No'}
                                    </p>
                                </div>
                                <Button onClick={() => handleTestProvider('resend')} loading={testingProvider === 'resend'} size={Button.Sizes.Small}>
                                    <FontAwesomeIcon icon={faVial} className={'mr-1'} />
                                    Test Resend Connection
                                </Button>
                            </div>
                            {testResults.resend && <ResultBanner result={testResults.resend} />}
                        </Card>
                    </div>
                )}

                {activeTab === 'testing' && (
                    <div className={'space-y-4'}>
                        <Card>
                            <div className={'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between'}>
                                <div>
                                    <Label>Send test email</Label>
                                    <p className={'text-sm text-gray-400'}>
                                        Uses the active provider ({transport.toUpperCase()}).
                                    </p>
                                </div>
                                <StatusBadge status={transport === 'smtp' ? 'info' : 'secondary'} />
                            </div>
                            <div className={'mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto]'}>
                                <Input
                                    type={'email'}
                                    value={testRecipient}
                                    onChange={(e) => setTestRecipient(e.target.value)}
                                    placeholder={'recipient@example.com'}
                                />
                                <Button onClick={handleSendTestEmail} loading={testingSend} disabled={!testRecipient}>
                                    <FontAwesomeIcon icon={faPaperPlane} className={'mr-2'} />
                                    Send Test Email
                                </Button>
                            </div>
                            {testResults.email && <ResultBanner result={testResults.email} />}
                        </Card>
                        <Card>
                            <div className={'space-y-2'}>
                                <Label>Tips</Label>
                                <ul className={'list-disc space-y-1 pl-5 text-sm text-gray-400'}>
                                    <li>Use a monitored inbox for Reply-To to capture responses.</li>
                                    <li>Configure SPF/DKIM for your domain to avoid spam folders.</li>
                                    <li>Connection tests validate configuration before attempting delivery.</li>
                                </ul>
                            </div>
                        </Card>
                    </div>
                )}

                <div
                    className={
                        'flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between'
                    }
                    style={{ backgroundColor: secondary, borderColor: headers }}
                >
                    <p className={'text-sm font-semibold text-white'}>Save Settings</p>
                    <div className={'flex items-center gap-3'}>
                        {!hasChanges && <span className={'text-xs text-gray-400'}>No changes to save</span>}
                        <Button onClick={handleSave} disabled={!hasChanges || saving} loading={saving}>
                            Save
                        </Button>
                    </div>
                </div>
            </div>
        </AdminBox>
    );
};

const TabList = ({ active, onSelect, primary }: { active: TabKey; onSelect: (tab: TabKey) => void; primary: string }) => {
    const tabs: Array<{ key: TabKey; label: string; icon: IconProp }> = [
        { key: 'overview', label: 'Overview', icon: faEnvelope },
        { key: 'smtp', label: 'SMTP', icon: faServer },
        { key: 'resend', label: 'Resend', icon: faPlug },
        { key: 'testing', label: 'Testing', icon: faVial },
    ];

    return (
        <div className={'border-b border-neutral-700'}>
            <div className={'flex flex-wrap gap-4'}>
                {tabs.map((tab) => {
                    const isActive = active === tab.key;
                    return (
                        <button
                            key={tab.key}
                            type={'button'}
                            onClick={() => onSelect(tab.key)}
                            className={
                                'flex items-center gap-2 px-2 pb-2 text-sm font-semibold border-b-2 transition-colors'
                            }
                            style={{
                                borderBottomColor: isActive ? primary : 'transparent',
                                color: isActive ? '#fff' : '#9ca3af',
                            }}
                        >
                            <FontAwesomeIcon icon={tab.icon} />
                            {tab.label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
};

const Card = ({ children, className }: { children: ReactNode; className?: string }) => {
    const { headers } = useStoreState((state) => state.theme.data!.colors);
    return (
        <div
            className={`rounded-lg border p-4 ${className ?? ''}`}
            style={{ backgroundColor: headers, borderColor: '#00000040' }}
        >
            {children}
        </div>
    );
};

const ProviderPill = ({ label, active, onClick, primary }: { label: string; active: boolean; onClick: () => void; primary: string }) => (
    <button
        type={'button'}
        onClick={onClick}
        className={`flex w-full items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm font-semibold transition-colors ${
            active ? 'text-white' : 'text-gray-200 hover:text-white'
        } ${active ? '' : 'bg-neutral-800 border-neutral-700 hover:border-neutral-500'}`}
        style={active ? { backgroundColor: primary, borderColor: primary } : undefined}
    >
        <span
            className={`h-2 w-2 rounded-full ${active ? '' : 'bg-neutral-600'}`}
            style={active ? { backgroundColor: '#ffffff' } : undefined}
        />
        {label}
    </button>
);

const InputField = ({
    label,
    value,
    onChange,
    placeholder,
    type = 'text',
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    type?: string;
}) => (
    <div className={'space-y-1'}>
        <Label>{label}</Label>
        <Input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} type={type} />
    </div>
);

const StatusBadge = ({ status }: { status: 'success' | 'warning' | 'info' | 'secondary' }) => {
    const label = status === 'success' ? 'Success' : status === 'warning' ? 'Warning' : status === 'info' ? 'Info' : 'Status';
    const map = {
        success: <Pill type={'success'}>{label}</Pill>,
        warning: <Pill type={'danger'}>{label}</Pill>,
        info: <Pill type={'info'}>{label}</Pill>,
        secondary: <Pill>{label}</Pill>,
    } as const;

    return map[status];
};

const StatusCard = ({
    title,
    active,
    configured,
    lastTest,
    onTest,
    testing,
}: {
    title: string;
    active: boolean;
    configured: boolean;
    lastTest?: TestResult;
    onTest: () => void;
    testing: boolean;
}) => {
    const { headers, secondary } = useStoreState((state) => state.theme.data!.colors);

    return (
        <div
            className={'flex h-full flex-col gap-3 rounded-lg border p-4'}
            style={{ backgroundColor: headers, borderColor: '#00000040' }}
        >
            <div className={'flex flex-wrap items-center justify-between gap-3'}>
                <div className={'space-y-1'}>
                    <h3 className={'text-lg font-semibold text-white'}>{title}</h3>
                    <div className={'flex flex-wrap gap-2 text-sm text-gray-300'}>
                        <Pill type={active ? 'success' : 'danger'}>{active ? 'Active' : 'Inactive'}</Pill>
                        <Pill type={configured ? 'success' : 'danger'}>{configured ? 'Configured' : 'Incomplete'}</Pill>
                    </div>
                </div>
                <Button onClick={onTest} loading={testing} size={Button.Sizes.Small}>
                    <FontAwesomeIcon icon={faVial} className={'mr-1'} />
                    Test
                </Button>
            </div>
            <div className={'text-sm text-gray-300'}>
                {lastTest
                    ? `${lastTest.status === 'success' ? 'Last success' : 'Last failure'} • ${new Date(
                          lastTest.tested_at,
                      ).toLocaleString()}`
                    : 'No tests yet'}
            </div>
        </div>
    );
};

const ResultBanner = ({ result }: { result: TestResult }) => (
    <div
        className={`mt-3 rounded-md border p-3 text-sm ${
            result.status === 'success'
                ? 'border-green-600/50 bg-green-950/40 text-green-100'
                : 'border-amber-500/50 bg-amber-950/40 text-amber-100'
        }`}
    >
        <div className={'flex items-center justify-between'}>
            <span className={'font-semibold'}>
                {result.status === 'success' ? 'Success' : 'Failure'} — {result.provider.toUpperCase()}
            </span>
            <span className={'text-xs text-gray-300'}>{formatDate(result.tested_at)}</span>
        </div>
        <p className={'mt-1 text-sm text-white'}>{result.message}</p>
        {result.code && <p className={'text-xs text-gray-400'}>Code: {result.code}</p>}
    </div>
);

const formatDate = (value?: string) => (value ? new Date(value).toLocaleString() : '');

const extractErrorMessage = (error: unknown, fallback: string) => {
    if (!error) return fallback;
    if (typeof error === 'string') return error;
    if (typeof error === 'object' && error !== null && 'message' in error) {
        const message = (error as { message?: string }).message;
        return message || fallback;
    }
    return fallback;
};
