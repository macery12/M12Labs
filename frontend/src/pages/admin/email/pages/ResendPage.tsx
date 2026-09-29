import { m } from '@/i18n/messages';
import { useState } from 'react';
import { FlaskConical } from 'lucide-react';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Spinner, FullPageSpinner } from '@/components/ui/Spinner';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import { testResendConnection, type EmailResponse } from '@/api/email';
import { useEmailSettings } from '../useEmailSettings';
import { SettingsCard, SaveBar, LabeledField, TonePill } from '../parts';
import { TestResultBanner } from './TestResultBanner';

// Resend transport configuration: API key and a connection check. Resend
// enforces its own plan limits; the panel no longer mirrors them.
export default function ResendPage() {
    const { settings, isLoading, save, saving } = useEmailSettings();
    const push = useFlashes(s => s.push);

    const [confirmDelete, setConfirmDelete] = useState(false);
    const [apiKey, setApiKey] = useState('');
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<EmailResponse | null>(null);

    if (isLoading || !settings) return <FullPageSpinner />;

    const active = settings.transport === 'resend';
    const dirty = apiKey.trim().length > 0;

    const onSave = async () => {
        await save({ api_key: apiKey.trim() });
        setApiKey('');
    };

    const clearKey = async () => {
        await save({ api_key: '', clear_api_key: true });
        setApiKey('');
    };

    const runTest = () => {
        setTesting(true);
        testResendConnection()
            .then(setResult)
            .catch(err => push({ type: 'error', message: firstError(err) ?? m['admin.email.test.failed']() }))
            .finally(() => setTesting(false));
    };

    return (
        <div className="flex flex-col gap-5">
            <SettingsCard
                title={m['admin.email.resend.title']()}
                description={m['admin.email.resend.desc']()}
                right={
                    <TonePill tone={active ? 'success' : 'neutral'}>
                        {active ? m['ui.labels.activeTransport']() : m['ui.states.inactive']()}
                    </TonePill>
                }
            >
                <LabeledField label={m['admin.email.resend.apiKey']()} hint={m['admin.email.resend.apiKeyHint']()}>
                    <Input
                        type="password"
                        value={apiKey}
                        onChange={e => setApiKey(e.target.value)}
                        placeholder={settings.resend.api_key ? m['admin.email.resend.keySaved']() : m['admin.email.resend.keyEnter']()}
                    />
                </LabeledField>
                {/* Only when there is a key to delete; it deleted on one click, so
                    it now confirms first. */}
                {settings.resend.api_key && (
                    <div className="mt-3">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-[var(--color-danger)] hover:bg-[var(--color-danger)]/10 hover:text-[var(--color-danger)]"
                            onClick={() => setConfirmDelete(true)}
                            disabled={saving}
                        >
                            {m['admin.email.resend.deleteKey']()}
                        </Button>
                    </div>
                )}
                <ConfirmDialog
                    open={confirmDelete}
                    onClose={() => setConfirmDelete(false)}
                    title={m['admin.email.resend.deleteKeyTitle']()}
                    body={m['admin.email.resend.deleteKeyBody']()}
                    confirmLabel={m['admin.email.resend.deleteKey']()}
                    cancelLabel={m['common.actions.cancel']()}
                    busy={saving}
                    onConfirm={async () => {
                        await clearKey();
                        setConfirmDelete(false);
                    }}
                />
            </SettingsCard>

            <SettingsCard
                title={m['ui.labels.connectionCheck']()}
                description={m['admin.email.resend.checkDesc']()}
                right={
                    <Button variant="secondary" size="sm" onClick={runTest} disabled={testing}>
                        {testing ? <Spinner className="h-4 w-4" /> : <FlaskConical className="h-4 w-4" />}
                        {m['ui.actions.checkConnection']()}
                    </Button>
                }
            >
                <p className="text-xs text-[var(--color-ink-faint)]">
                    {m['admin.email.smtp.configuredState']({ state: settings.resend.api_key
                            ? m['ui.states.configured']()
                            : m['admin.email.overview.incomplete']() })}
                </p>
                {result && <TestResultBanner result={result} />}
            </SettingsCard>

            <SaveBar dirty={dirty} saving={saving} onDiscard={() => setApiKey('')} onSave={onSave} />
        </div>
    );
}
