import { m } from '@/i18n/messages';
import { useEffect, useMemo, useState } from 'react';
import { FlaskConical } from 'lucide-react';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Spinner, FullPageSpinner } from '@/components/ui/Spinner';
import { FieldGrid } from '@/components/ui/editorChrome';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import {
    testResendConnection,
    testSmtpConnection,
    type EmailProvider,
    type EmailResponse,
    type EmailSettings,
} from '@/api/email';
import { useEmailSettings } from '../useEmailSettings';
import { SettingsCard, SaveBar, LabeledField, TonePill } from '../parts';
import { TestResultBanner, resultFromError } from './TestResultBanner';

interface Form {
    primary: EmailProvider;
    backup: EmailProvider | 'none';
    fromName: string;
    fromEmail: string;
    replyTo: string;
    host: string;
    port: string;
    username: string;
    encryption: string;
}

const fromSettings = (s: EmailSettings): Form => ({
    primary: s.primary,
    backup: s.backup,
    fromName: s.from_name,
    fromEmail: s.from_email,
    replyTo: s.reply_to,
    host: s.smtp.host,
    port: s.smtp.port,
    username: s.smtp.username,
    encryption: s.smtp.encryption,
});

// The well-known submission ports each imply one mode: 465 is implicit TLS
// ("SSL" here), 587 and 2525 are STARTTLS ("TLS"). The wrong pairing fails as
// a vague timeout, so say so before the check does. Other ports are left alone.
const EXPECTED_ENCRYPTION: Record<string, string> = { '465': 'ssl', '587': 'tls', '2525': 'tls' };

const providerLabel = (p: EmailProvider) => (p === 'smtp' ? m['admin.email.providers.smtp']() : m['admin.email.providers.resend']());

// Everything about where mail goes: which provider is primary, the optional
// backup it fails over to, the one sender identity both use, and each
// provider's credentials with its own connection check. One form, one save.
export default function ProvidersPage() {
    const { settings, isLoading, save, saving } = useEmailSettings();

    const [form, setForm] = useState<Form | null>(null);
    const [password, setPassword] = useState('');
    const [apiKey, setApiKey] = useState('');
    const [confirmDeleteKey, setConfirmDeleteKey] = useState(false);

    const initial = useMemo(() => (settings ? fromSettings(settings) : null), [settings]);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect -- intentional effect: syncs state to prop/query/filter changes
        if (initial) setForm(initial);
    }, [initial]);

    const dirty = useMemo(() => {
        if (!initial || !form) return false;
        return (
            (Object.keys(initial) as (keyof Form)[]).some(k => initial[k] !== form[k]) ||
            password.trim().length > 0 ||
            apiKey.trim().length > 0
        );
    }, [initial, form, password, apiKey]);

    if (isLoading || !settings || !form) return <FullPageSpinner />;

    const set = (patch: Partial<Form>) => setForm(f => (f ? { ...f, ...patch } : f));

    // A backup equal to the primary would only retry the same failure.
    const setPrimary = (primary: EmailProvider) => set({ primary, backup: form.backup === primary ? 'none' : form.backup });

    const onSave = async () => {
        await save({
            primary: form.primary,
            backup: form.backup,
            from_name: form.fromName,
            from_email: form.fromEmail,
            reply_to: form.replyTo,
            smtp_host: form.host,
            smtp_port: form.port,
            smtp_username: form.username,
            smtp_encryption: form.encryption,
            ...(password.trim() ? { smtp_password: password.trim() } : {}),
            ...(apiKey.trim() ? { api_key: apiKey.trim() } : {}),
        });
        setPassword('');
        setApiKey('');
    };

    const onDiscard = () => {
        if (initial) setForm(initial);
        setPassword('');
        setApiKey('');
    };

    const other: EmailProvider = form.primary === 'smtp' ? 'resend' : 'smtp';
    const role = (p: EmailProvider) =>
        settings.primary === p ? 'primary' : settings.backup === p ? 'backup' : 'unused';

    const expected = EXPECTED_ENCRYPTION[form.port.trim()];
    const mismatch = expected && form.encryption !== expected ? expected : null;

    return (
        <div className="flex flex-col gap-5">
            <SettingsCard title={m['admin.email.providers.routingTitle']()} description={m['admin.email.providers.routingDesc']()}>
                <FieldGrid>
                    <LabeledField label={m['admin.email.providers.primary']()}>
                        <Select
                            value={form.primary}
                            onChange={v => setPrimary(v as EmailProvider)}
                            options={[
                                { value: 'smtp', label: providerLabel('smtp') },
                                { value: 'resend', label: providerLabel('resend') },
                            ]}
                        />
                    </LabeledField>
                    <LabeledField label={m['admin.email.providers.backup']()}>
                        <Select
                            value={form.backup}
                            onChange={v => set({ backup: v as EmailProvider | 'none' })}
                            options={[
                                { value: 'none', label: m['admin.email.providers.backupNone']() },
                                { value: other, label: providerLabel(other) },
                            ]}
                        />
                    </LabeledField>
                </FieldGrid>
                {form.backup !== 'none' && (
                    <p className="mt-3 text-xs text-[var(--color-ink-faint)]">{m['admin.email.providers.backupNote']()}</p>
                )}
            </SettingsCard>

            <SettingsCard title={m['admin.email.providers.senderTitle']()} description={m['admin.email.providers.senderDesc']()}>
                <FieldGrid>
                    <LabeledField label={m['admin.email.providers.fromName']()}>
                        <Input
                            value={form.fromName}
                            onChange={e => set({ fromName: e.target.value })}
                            placeholder={m['admin.email.providers.fromNamePlaceholder']()}
                        />
                    </LabeledField>
                    <LabeledField label={m['admin.email.providers.fromEmail']()} required>
                        <Input
                            type="email"
                            value={form.fromEmail}
                            onChange={e => set({ fromEmail: e.target.value })}
                            placeholder={m['admin.email.providers.fromEmailPlaceholder']()}
                        />
                    </LabeledField>
                    <LabeledField label={m['admin.email.providers.replyTo']()} hint={m['admin.email.providers.replyToHint']()}>
                        <Input
                            type="email"
                            value={form.replyTo}
                            onChange={e => set({ replyTo: e.target.value })}
                            placeholder={m['admin.email.providers.replyToPlaceholder']()}
                        />
                    </LabeledField>
                </FieldGrid>
            </SettingsCard>

            <SettingsCard
                title={m['admin.email.smtp.title']()}
                description={m['admin.email.smtp.desc']()}
                right={<ProviderPills role={role('smtp')} problem={settings.status.smtp} />}
            >
                <FieldGrid>
                    <LabeledField label={m['ui.labels.host']()}>
                        <Input value={form.host} onChange={e => set({ host: e.target.value })} placeholder="smtp.yourdomain.com" />
                    </LabeledField>
                    <LabeledField label={m['ui.labels.port']()}>
                        <Input type="number" value={form.port} onChange={e => set({ port: e.target.value })} placeholder="587" />
                    </LabeledField>
                    <LabeledField label={m['ui.labels.username']()}>
                        <Input
                            value={form.username}
                            onChange={e => set({ username: e.target.value })}
                            placeholder="user@yourdomain.com"
                        />
                    </LabeledField>
                    <LabeledField label={m['admin.email.smtp.encryption']()}>
                        <Select
                            value={form.encryption || 'none'}
                            onChange={v => set({ encryption: v === 'none' ? '' : v })}
                            options={[
                                { value: 'none', label: m['ui.states.none']() },
                                { value: 'tls', label: 'TLS' },
                                { value: 'ssl', label: 'SSL' },
                            ]}
                        />
                    </LabeledField>
                    {mismatch && (
                        <div className="col-span-full flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-lg border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 px-3 py-2.5 text-sm text-[var(--color-ink)]">
                            <span className="min-w-0 flex-1">
                                {m['admin.email.smtp.portMismatch']({
                                    port: form.port,
                                    expected: mismatch.toUpperCase(),
                                    actual: form.encryption ? form.encryption.toUpperCase() : m['ui.states.none'](),
                                })}
                            </span>
                            <button
                                type="button"
                                onClick={() => set({ encryption: mismatch })}
                                className="text-sm font-medium text-[var(--brand)] hover:underline"
                            >
                                {m['admin.email.smtp.portMismatchFix']({ expected: mismatch.toUpperCase() })}
                            </button>
                        </div>
                    )}
                    <LabeledField label={m['ui.labels.password']()} hint={m['admin.email.smtp.passwordHint']()}>
                        <Input
                            type="password"
                            value={password}
                            onChange={e => setPassword(e.target.value)}
                            placeholder={
                                settings.smtp.password_set
                                    ? m['admin.email.smtp.passwordSaved']()
                                    : m['admin.email.smtp.passwordEnter']()
                            }
                        />
                    </LabeledField>
                </FieldGrid>
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <ConnectionCheck provider="smtp" dirty={dirty} run={testSmtpConnection} />
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => save({ smtp_password: '', clear_smtp_password: true })}
                        disabled={!settings.smtp.password_set || saving}
                    >
                        {m['admin.email.smtp.clearPassword']()}
                    </Button>
                </div>
            </SettingsCard>

            <SettingsCard
                title={m['admin.email.resend.title']()}
                description={m['admin.email.resend.desc']()}
                right={<ProviderPills role={role('resend')} problem={settings.status.resend} />}
            >
                <LabeledField label={m['admin.email.resend.apiKey']()} hint={m['admin.email.resend.apiKeyHint']()}>
                    <Input
                        type="password"
                        value={apiKey}
                        onChange={e => setApiKey(e.target.value)}
                        placeholder={settings.resend.api_key ? m['admin.email.resend.keySaved']() : m['admin.email.resend.keyEnter']()}
                    />
                </LabeledField>
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <ConnectionCheck provider="resend" dirty={dirty} run={testResendConnection} />
                    {/* Only when there is a key to delete; it deleted on one click, so
                        it confirms first. */}
                    {settings.resend.api_key && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 hover:text-[var(--color-danger)]"
                            onClick={() => setConfirmDeleteKey(true)}
                            disabled={saving}
                        >
                            {m['admin.email.resend.deleteKey']()}
                        </Button>
                    )}
                </div>
                <ConfirmDialog
                    open={confirmDeleteKey}
                    onClose={() => setConfirmDeleteKey(false)}
                    title={m['admin.email.resend.deleteKeyTitle']()}
                    body={m['admin.email.resend.deleteKeyBody']()}
                    confirmLabel={m['admin.email.resend.deleteKey']()}
                    cancelLabel={m['common.actions.cancel']()}
                    busy={saving}
                    onConfirm={async () => {
                        await save({ api_key: '', clear_api_key: true });
                        setApiKey('');
                        setConfirmDeleteKey(false);
                    }}
                />
            </SettingsCard>

            <SaveBar dirty={dirty} saving={saving} onDiscard={onDiscard} onSave={onSave} />
        </div>
    );
}

// Role (primary / backup / not used) and whether the saved settings are
// complete; the reason shows on hover when they are not.
function ProviderPills({ role, problem }: { role: 'primary' | 'backup' | 'unused'; problem: string | null }) {
    const roleLabel = {
        primary: m['admin.email.providers.rolePrimary'](),
        backup: m['admin.email.providers.roleBackup'](),
        unused: m['admin.email.providers.roleUnused'](),
    }[role];

    return (
        <div className="flex flex-wrap items-center gap-2">
            <TonePill tone={role === 'unused' ? 'neutral' : 'success'}>{roleLabel}</TonePill>
            <span title={problem ?? undefined}>
                <TonePill tone={problem ? 'warning' : 'success'}>
                    {problem ? m['admin.email.providers.incomplete']() : m['admin.email.providers.ready']()}
                </TonePill>
            </span>
        </div>
    );
}

// Sends a check message to the sender's own address through this provider
// alone. It uses the saved settings, so it waits for unsaved edits.
function ConnectionCheck({
    provider,
    dirty,
    run,
}: {
    provider: EmailProvider;
    dirty: boolean;
    run: () => Promise<EmailResponse>;
}) {
    const push = useFlashes(s => s.push);
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<EmailResponse | null>(null);

    const check = () => {
        setTesting(true);
        run()
            .then(setResult)
            .catch(err => {
                const failed = resultFromError(err);
                if (failed) setResult(failed);
                else push({ type: 'error', message: firstError(err) ?? m['admin.email.test.failed']() });
            })
            .finally(() => setTesting(false));
    };

    return (
        <>
            <Button
                variant="secondary"
                size="sm"
                onClick={check}
                disabled={testing || dirty}
                title={dirty ? m['admin.email.providers.saveFirst']() : m['admin.email.providers.checkDesc']({ provider: providerLabel(provider) })}
            >
                {testing ? <Spinner className="h-4 w-4" /> : <FlaskConical className="h-4 w-4" />}
                {m['ui.actions.checkConnection']()}
            </Button>
            {result && (
                <div className="basis-full">
                    <TestResultBanner result={result} />
                </div>
            )}
        </>
    );
}
