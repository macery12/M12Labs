import { m } from '@/i18n/messages';
import { Lock } from 'lucide-react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Switch } from '@/components/ui/Switch';
import { FullPageSpinner } from '@/components/ui/Spinner';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import {
    getNotificationSettings,
    updateNotificationSetting,
    type EmailNotificationSetting,
    type NotificationSettingsResponse,
} from '@/api/email';
import { SettingsCard, TonePill } from '../parts';

const NOTIF_KEY = ['admin', 'email', 'notifications'] as const;

// Categories arrive as raw keys ("auth", "billing"). Known ones get a label; an
// unknown one (a new category, or one an extension adds) is title-cased.
const CATEGORY_LABELS: Record<string, () => string> = {
    auth: m['admin.email.notifications.category.auth'],
    billing: m['ui.labels.billing'],
    server: m['ui.labels.servers'],
};
const categoryLabel = (key: string) =>
    CATEGORY_LABELS[key]?.() ?? key.replace(/[_-]+/g, ' ').replace(/^\w/, c => c.toUpperCase());

// Per-template notification toggles, grouped by category. Toggling is optimistic
// against the react-query cache and reverts on error.
export default function NotificationsPage() {
    const qc = useQueryClient();
    const push = useFlashes(s => s.push);

    const query = useQuery({ queryKey: NOTIF_KEY, queryFn: getNotificationSettings });

    const mutation = useMutation({
        mutationFn: ({ id, enabled }: { id: number; enabled: boolean }) => updateNotificationSetting(id, enabled),
        onMutate: async ({ id, enabled }) => {
            await qc.cancelQueries({ queryKey: NOTIF_KEY });
            const prev = qc.getQueryData<NotificationSettingsResponse>(NOTIF_KEY);
            if (prev) {
                const next: NotificationSettingsResponse = {
                    categories: Object.fromEntries(
                        Object.entries(prev.categories).map(([cat, items]) => [
                            cat,
                            items.map(i => (i.id === id ? { ...i, enabled } : i)),
                        ]),
                    ),
                };
                qc.setQueryData(NOTIF_KEY, next);
            }
            return { prev };
        },
        onError: (err, _vars, ctx) => {
            if (ctx?.prev) qc.setQueryData(NOTIF_KEY, ctx.prev);
            push({ type: 'error', message: firstError(err) ?? m['admin.email.notifications.saveError']() });
        },
    });

    if (query.isLoading || !query.data) return <FullPageSpinner />;

    const categories = Object.entries(query.data.categories);

    if (categories.length === 0) {
        return (
            <SettingsCard title={m['admin.email.notifications.title']()} description={m['admin.email.notifications.desc']()}>
                <p className="text-sm text-[var(--color-ink-muted)]">{m['admin.email.notifications.empty']()}</p>
            </SettingsCard>
        );
    }

    return (
        <div className="flex flex-col gap-5">
            <div>
                <h2 className="text-lg font-semibold text-[var(--color-ink)]">{m['admin.email.notifications.title']()}</h2>
                <p className="mt-1 text-sm text-[var(--color-ink-muted)]">{m['admin.email.notifications.desc']()}</p>
            </div>
            {categories.map(([category, items]) => (
                <SettingsCard key={category} title={categoryLabel(category)}>
                    <ul className="flex flex-col divide-y divide-[var(--color-border)]">
                        {items.map(item => (
                            <Row
                                key={item.id}
                                item={item}
                                onToggle={enabled => mutation.mutate({ id: item.id, enabled })}
                            />
                        ))}
                    </ul>
                </SettingsCard>
            ))}
        </div>
    );
}

function Row({
    item,
    onToggle,
}: {
    item: EmailNotificationSetting;
    onToggle: (enabled: boolean) => void;
}) {
    return (
        <li className="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
            <div className="min-w-0">
                {/* The template key is for whoever greps the logs; it lives in the
                    name's tooltip rather than as a code chip under every row. */}
                <div className="flex flex-wrap items-center gap-2">
                    <span
                        className="text-sm font-medium text-[var(--color-ink)]"
                        title={m['admin.email.notifications.templateKey']({ key: item.template_key })}
                    >
                        {item.name}
                    </span>
                </div>
                {item.description && (
                    <p className="mt-0.5 text-xs text-[var(--color-ink-muted)]">{item.description}</p>
                )}
                {item.locked && (
                    <p className="mt-0.5 text-xs text-[var(--color-ink-faint)]">{m['admin.email.notifications.lockedHint']()}</p>
                )}
            </div>
            {/* Password reset and verification are how people get into their
                accounts; the server refuses to switch them off, so no toggle. */}
            {item.locked ? (
                <TonePill tone="neutral">
                    <Lock className="mr-1 h-3 w-3" />
                    {m['admin.email.notifications.locked']()}
                </TonePill>
            ) : (
                <Switch checked={item.enabled} onChange={onToggle} label={item.name} />
            )}
        </li>
    );
}
