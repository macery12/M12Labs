import { m } from '@/i18n/messages';
import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Switch } from '@/components/ui/Switch';
import { FullPageSpinner } from '@/components/ui/Spinner';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import {
    getVerificationRules,
    updateVerificationRules,
    type VerificationArea,
    type VerificationRules,
} from '@/api/email';
import { useEmailSettings } from '../useEmailSettings';
import { SettingsCard, SaveBar } from '../parts';

const RULES_KEY = ['admin', 'email', 'verificationRules'] as const;

interface AreaDef {
    area: VerificationArea;
    label: () => string;
    desc: () => string;
    // Orders only has read routes; nothing checks "change" for it.
    readOnly?: boolean;
}

const AREAS: AreaDef[] = [
    { area: 'billing', label: m['admin.email.verification.area.billing'], desc: m['admin.email.verification.area.billingDesc'] },
    {
        area: 'orders',
        label: m['admin.email.verification.area.orders'],
        desc: m['admin.email.verification.area.ordersDesc'],
        readOnly: true,
    },
    {
        area: 'credentials',
        label: m['admin.email.verification.area.credentials'],
        desc: m['admin.email.verification.area.credentialsDesc'],
    },
    { area: 'tickets', label: m['admin.email.verification.area.tickets'], desc: m['admin.email.verification.area.ticketsDesc'] },
];

// What someone who hasn't verified their email address may open or change.
// Enforced by the verified.view / verified.interact middleware, and only while
// mail delivery is on: with it off nobody could receive the link.
export default function VerificationPage() {
    const qc = useQueryClient();
    const push = useFlashes(s => s.push);
    const { settings } = useEmailSettings();
    const query = useQuery({ queryKey: RULES_KEY, queryFn: getVerificationRules });
    const [rules, setRules] = useState<VerificationRules | null>(null);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect -- intentional effect: syncs state to prop/query/filter changes
        if (query.data) setRules(query.data);
    }, [query.data]);

    const mutation = useMutation({
        mutationFn: updateVerificationRules,
        onSuccess: data => {
            qc.setQueryData(RULES_KEY, data);
            push({ type: 'success', message: m['admin.email.verification.saved']() });
        },
        onError: err => push({ type: 'error', message: firstError(err) ?? m['admin.email.verification.saveError']() }),
    });

    const dirty = useMemo(
        () => Boolean(rules && query.data && JSON.stringify(rules) !== JSON.stringify(query.data)),
        [rules, query.data],
    );

    if (query.isLoading || !rules) return <FullPageSpinner />;

    const setRule = (area: VerificationArea, patch: Partial<VerificationRules[VerificationArea]>) =>
        setRules(r => {
            if (!r) return r;
            const next = { ...r[area], ...patch };
            // Changing something you can't open means nothing, so closing an
            // area also closes changing it.
            if (!next.can_view) next.can_interact = false;
            return { ...r, [area]: next };
        });

    return (
        <div className="flex flex-col gap-5">
            <SettingsCard title={m['admin.email.verification.title']()} description={m['admin.email.verification.desc']()}>
                {settings && !settings.enabled && (
                    <p className="mb-4 rounded-lg border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 px-3 py-2.5 text-sm text-[var(--color-ink)]">
                        {m['admin.email.verification.offNote']()}
                    </p>
                )}
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-[var(--color-border)] text-left text-[11px] uppercase tracking-wide text-[var(--color-ink-faint)]">
                                <th className="py-2 pr-4 font-semibold">{m['admin.email.verification.colArea']()}</th>
                                <th className="w-24 px-3 py-2 text-center font-semibold">{m['admin.email.verification.colView']()}</th>
                                <th className="w-24 px-3 py-2 text-center font-semibold">{m['admin.email.verification.colChange']()}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {AREAS.map(({ area, label, desc, readOnly }) => (
                                <tr key={area} className="border-b border-[var(--color-border)] last:border-0">
                                    <td className="py-3 pr-4 align-top">
                                        <p className="font-medium text-[var(--color-ink)]">{label()}</p>
                                        <p className="mt-0.5 text-xs text-[var(--color-ink-muted)]">{desc()}</p>
                                    </td>
                                    <td className="px-3 py-3 text-center align-middle">
                                        <div className="flex justify-center">
                                            <Switch
                                                checked={rules[area].can_view}
                                                onChange={v => setRule(area, { can_view: v })}
                                                label={`${label()}: ${m['admin.email.verification.colView']()}`}
                                            />
                                        </div>
                                    </td>
                                    <td className="px-3 py-3 text-center align-middle">
                                        {readOnly ? (
                                            <span
                                                className="text-[var(--color-ink-faint)]"
                                                title={m['admin.email.verification.nothingToChange']()}
                                            >
                                                —
                                            </span>
                                        ) : (
                                            <div className="flex justify-center">
                                                <Switch
                                                    checked={rules[area].can_interact}
                                                    onChange={v => setRule(area, { can_interact: v })}
                                                    disabled={!rules[area].can_view}
                                                    label={`${label()}: ${m['admin.email.verification.colChange']()}`}
                                                />
                                            </div>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </SettingsCard>

            <SaveBar
                dirty={dirty}
                saving={mutation.isPending}
                onDiscard={() => query.data && setRules(query.data)}
                onSave={() => mutation.mutate(rules)}
            />
        </div>
    );
}
