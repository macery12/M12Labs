import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FlaskConical, CalendarDays, CalendarRange } from 'lucide-react';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Button } from '@/components/ui/Button';
import { Meter } from '@/components/ui/Meter';
import { Spinner, FullPageSpinner } from '@/components/ui/Spinner';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import { testResendConnection, type EmailResponse, type ResendPlanKey } from '@/api/email';
import { useEmailSettings } from '../useEmailSettings';
import { SettingsCard, SaveBar, LabeledField, TonePill } from '../parts';
import { TestResultBanner } from './TestResultBanner';

// Resend transport configuration: API key, plan tier, optional custom quotas,
// live usage meters, and a connection check.
export default function ResendPage() {
    const { t } = useTranslation('admin');
    const { settings, isLoading, save, saving } = useEmailSettings();
    const push = useFlashes(s => s.push);

    const [apiKey, setApiKey] = useState('');
    const [plan, setPlan] = useState<ResendPlanKey>('free');
    const [monthly, setMonthly] = useState('');
    const [daily, setDaily] = useState('');
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState<EmailResponse | null>(null);

    const initial = useMemo(() => {
        if (!settings) return null;
        return {
            plan: settings.resend_plan.key,
            monthly:
                settings.resend_plan.custom_monthly_limit != null
                    ? String(settings.resend_plan.custom_monthly_limit)
                    : '',
            daily:
                settings.resend_plan.custom_daily_limit != null
                    ? String(settings.resend_plan.custom_daily_limit)
                    : '',
        };
    }, [settings]);

    useEffect(() => {
        if (initial) {
            setPlan(initial.plan);
            setMonthly(initial.monthly);
            setDaily(initial.daily);
        }
    }, [initial]);

    const dirty = useMemo(() => {
        if (!initial) return false;
        return (
            apiKey.trim().length > 0 ||
            plan !== initial.plan ||
            monthly !== initial.monthly ||
            daily !== initial.daily
        );
    }, [initial, apiKey, plan, monthly, daily]);

    if (isLoading || !settings) return <FullPageSpinner />;

    const active = settings.transport === 'resend';
    const activePlan = settings.resend_plans.find(p => p.key === plan) ?? settings.resend_plan;
    const usage = settings.resend_usage;

    const onSave = async () => {
        await save({
            resend_plan: plan,
            ...(apiKey.trim() ? { api_key: apiKey.trim() } : {}),
            ...(monthly.trim() || plan === 'enterprise'
                ? { resend_custom_monthly_limit: monthly.trim() ? Number(monthly) : null }
                : {}),
            ...(daily.trim() || plan === 'enterprise'
                ? { resend_custom_daily_limit: daily.trim() ? Number(daily) : null }
                : {}),
        });
        setApiKey('');
    };

    const onDiscard = () => {
        if (initial) {
            setPlan(initial.plan);
            setMonthly(initial.monthly);
            setDaily(initial.daily);
        }
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
            .catch(err => push({ type: 'error', message: firstError(err) ?? t('email.test.failed') }))
            .finally(() => setTesting(false));
    };

    const meterPercent = (sent: number, limit: number | null) =>
        limit && limit > 0 ? Math.round((sent / limit) * 100) : null;
    const meterValue = (sent: number, limit: number | null, applies: boolean) =>
        !applies ? t('email.resend.noCap') : `${sent.toLocaleString()} / ${limit == null ? '∞' : limit.toLocaleString()}`;

    const dailyApplies = Boolean(activePlan.enforce_daily);
    const monthlyApplies = Boolean(activePlan.enforce_monthly);
    const dailyLimit = usage.daily_limit ?? activePlan.daily_limit;
    const monthlyLimit = usage.monthly_limit ?? activePlan.monthly_limit;

    return (
        <div className="flex flex-col gap-5">
            <SettingsCard
                title={t('email.resend.title')}
                description={t('email.resend.desc')}
                right={
                    <TonePill tone={active ? 'success' : 'neutral'}>
                        {active ? t('email.smtp.active') : t('email.smtp.inactive')}
                    </TonePill>
                }
            >
                <LabeledField label={t('email.resend.apiKey')} hint={t('email.resend.apiKeyHint')}>
                    <Input
                        type="password"
                        value={apiKey}
                        onChange={e => setApiKey(e.target.value)}
                        placeholder={settings.resend.api_key ? t('email.resend.keySaved') : t('email.resend.keyEnter')}
                    />
                </LabeledField>
                <div className="mt-3">
                    <Button variant="ghost" size="sm" onClick={clearKey} disabled={!settings.resend.api_key || saving}>
                        {t('email.resend.deleteKey')}
                    </Button>
                </div>
            </SettingsCard>

            <SettingsCard title={t('email.resend.planTitle')} description={t('email.resend.planDesc')}>
                <LabeledField label={t('email.resend.plan')}>
                    <Select
                        value={plan}
                        onChange={v => setPlan(v as ResendPlanKey)}
                        options={settings.resend_plans.map(p => ({ value: p.key, label: p.name }))}
                        className="sm:max-w-xs"
                    />
                </LabeledField>

                {activePlan.allows_custom_limits && (
                    <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <LabeledField label={t('email.resend.monthlyLimit')} hint={t('email.resend.unlimitedHint')}>
                            <Input
                                type="number"
                                value={monthly}
                                onChange={e => setMonthly(e.target.value)}
                                placeholder="250000"
                            />
                        </LabeledField>
                        <LabeledField label={t('email.resend.dailyLimit')} hint={t('email.resend.optionalHint')}>
                            <Input
                                type="number"
                                value={daily}
                                onChange={e => setDaily(e.target.value)}
                                placeholder="5000"
                            />
                        </LabeledField>
                    </div>
                )}

                <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Meter
                        icon={CalendarDays}
                        label={t('email.resend.dailyQuota')}
                        value={meterValue(usage.daily_sent, dailyLimit, dailyApplies)}
                        percent={dailyApplies ? meterPercent(usage.daily_sent, dailyLimit) : null}
                    />
                    <Meter
                        icon={CalendarRange}
                        label={t('email.resend.monthlyQuota')}
                        value={meterValue(usage.monthly_sent, monthlyLimit, monthlyApplies)}
                        percent={monthlyApplies ? meterPercent(usage.monthly_sent, monthlyLimit) : null}
                    />
                </div>
                <p className="mt-3 text-xs text-[var(--color-ink-faint)]">{t('email.resend.quotaNote')}</p>
            </SettingsCard>

            <SettingsCard
                title={t('email.resend.checkTitle')}
                description={t('email.resend.checkDesc')}
                right={
                    <Button variant="secondary" size="sm" onClick={runTest} disabled={testing}>
                        {testing ? <Spinner className="h-4 w-4" /> : <FlaskConical className="h-4 w-4" />}
                        {t('email.resend.checkButton')}
                    </Button>
                }
            >
                <p className="text-xs text-[var(--color-ink-faint)]">
                    {t('email.smtp.configuredState', {
                        state: settings.resend.api_key
                            ? t('email.overview.configured')
                            : t('email.overview.incomplete'),
                    })}
                </p>
                {result && <TestResultBanner result={result} />}
            </SettingsCard>

            <SaveBar dirty={dirty} saving={saving} onDiscard={onDiscard} onSave={onSave} />
        </div>
    );
}
