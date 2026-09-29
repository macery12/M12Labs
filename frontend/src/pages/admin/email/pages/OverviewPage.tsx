import { m } from '@/i18n/messages';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCircle2, AlertTriangle, PowerOff } from 'lucide-react';
import { Switch } from '@/components/ui/Switch';
import { Button } from '@/components/ui/Button';
import { FullPageSpinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';
import type { EmailProvider, EmailSettings } from '@/api/email';
import { useEmailSettings } from '../useEmailSettings';
import { SettingsCard, TonePill } from '../parts';

const providerLabel = (p: EmailProvider) => (p === 'smtp' ? m['admin.email.providers.smtp']() : m['admin.email.providers.resend']());

// Overview: the one delivery switch, whether mail can actually go out, and a
// summary of where it goes. Providers and the sender are edited on their page.
export default function OverviewPage() {
    const { settings, isLoading, save, saving } = useEmailSettings();
    const navigate = useNavigate();
    const [togglingEnabled, setTogglingEnabled] = useState(false);

    if (isLoading || !settings) return <FullPageSpinner />;

    const toggleEnabled = async (next: boolean) => {
        setTogglingEnabled(true);
        try {
            await save({ enabled: next });
        } finally {
            setTogglingEnabled(false);
        }
    };

    const primaryProblem = settings.status[settings.primary];
    const sender = settings.from_email
        ? settings.from_name
            ? `${settings.from_name} <${settings.from_email}>`
            : settings.from_email
        : null;

    return (
        <div className="flex flex-col gap-5">
            <SettingsCard
                title={m['admin.email.overview.deliveryTitle']()}
                description={m['admin.email.overview.deliveryDesc']()}
                right={
                    <div className="flex items-center gap-3">
                        <TonePill tone={settings.enabled ? 'success' : 'warning'}>
                            {settings.enabled ? m['admin.email.overview.on']() : m['ui.states.off']()}
                        </TonePill>
                        <Switch
                            checked={settings.enabled}
                            onChange={toggleEnabled}
                            disabled={togglingEnabled || saving}
                            label={m['admin.email.overview.deliveryTitle']()}
                        />
                    </div>
                }
            >
                <StateBanner settings={settings} problem={primaryProblem} />
            </SettingsCard>

            <SettingsCard
                title={m['admin.email.overview.routingTitle']()}
                description={m['admin.email.overview.routingDesc']()}
                right={
                    <Button variant="secondary" size="sm" onClick={() => navigate('/admin/email/providers')}>
                        {m['admin.email.overview.manage']()}
                    </Button>
                }
            >
                <dl className="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-3">
                    <Summary label={m['admin.email.providers.rolePrimary']()}>
                        <span>{providerLabel(settings.primary)}</span>
                        <span title={primaryProblem ?? undefined}>
                            <TonePill tone={primaryProblem ? 'warning' : 'success'}>
                                {primaryProblem ? m['admin.email.providers.incomplete']() : m['admin.email.providers.ready']()}
                            </TonePill>
                        </span>
                    </Summary>
                    <Summary label={m['admin.email.providers.roleBackup']()}>
                        {settings.backup === 'none' ? (
                            <span className="text-[var(--color-ink-muted)]">{m['admin.email.providers.backupNone']()}</span>
                        ) : (
                            <>
                                <span>{providerLabel(settings.backup)}</span>
                                <span title={settings.status[settings.backup] ?? undefined}>
                                    <TonePill tone={settings.status[settings.backup] ? 'warning' : 'success'}>
                                        {settings.status[settings.backup]
                                            ? m['admin.email.providers.incomplete']()
                                            : m['admin.email.providers.ready']()}
                                    </TonePill>
                                </span>
                            </>
                        )}
                    </Summary>
                    <Summary label={m['admin.email.overview.sender']()}>
                        {sender ? (
                            <span className="truncate" title={sender}>{sender}</span>
                        ) : (
                            <span className="text-[var(--color-warning)]">{m['admin.email.overview.senderMissing']()}</span>
                        )}
                    </Summary>
                </dl>
            </SettingsCard>
        </div>
    );
}

// Off, can't send (and why), or ready. "Can't send" is the one that matters:
// with the switch on, password resets still depend on it.
function StateBanner({ settings, problem }: { settings: EmailSettings; problem: string | null }) {
    const state = !settings.enabled ? 'off' : problem ? 'broken' : 'ready';

    const { Icon, tone, title, body } = {
        off: {
            Icon: PowerOff,
            tone: 'border-[var(--color-border-strong)] bg-[var(--color-surface-2)]/40',
            title: m['admin.email.overview.offTitle'](),
            body: m['admin.email.overview.offBody'](),
        },
        broken: {
            Icon: AlertTriangle,
            tone: 'border-[var(--color-danger)]/40 bg-[var(--color-danger)]/10',
            title: m['admin.email.overview.brokenTitle'](),
            body: m['admin.email.overview.brokenBody']({ reason: problem ?? '' }),
        },
        ready: {
            Icon: CheckCircle2,
            tone: 'border-[var(--color-accent)]/40 bg-[var(--color-accent)]/10',
            title: m['admin.email.overview.readyTitle'](),
            body:
                settings.backup === 'none'
                    ? m['admin.email.overview.readyBody']({ primary: providerLabel(settings.primary) })
                    : m['admin.email.overview.readyBodyBackup']({
                          primary: providerLabel(settings.primary),
                          backup: providerLabel(settings.backup),
                      }),
        },
    }[state];

    return (
        <div className={cn('flex items-start gap-3 rounded-lg border p-3 text-sm', tone)}>
            <Icon className="mt-0.5 h-4 w-4 shrink-0 text-[var(--color-ink-muted)]" />
            <div className="min-w-0">
                <p className="font-semibold text-[var(--color-ink)]">{title}</p>
                <p className="mt-0.5 text-[var(--color-ink-muted)]">{body}</p>
            </div>
        </div>
    );
}

function Summary({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <dt className="text-[11px] font-semibold uppercase tracking-wide text-[var(--color-ink-faint)]">{label}</dt>
            <dd className="flex min-w-0 items-center gap-2 text-sm text-[var(--color-ink)]">{children}</dd>
        </div>
    );
}
