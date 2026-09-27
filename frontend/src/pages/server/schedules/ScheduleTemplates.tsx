import { useMutation } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Archive, CalendarPlus, RotateCcw, type LucideIcon } from 'lucide-react';
import { m } from '@/i18n/messages';
import { useServer } from '@/components/server/ServerContext';
import { useFlashes } from '@/state/flashes';
import { firstError } from '@/lib/apiError';
import { saveSchedule, saveTask, type Cron, type TaskInput } from '@/api/schedules';
import { Spinner } from '@/components/ui/Spinner';

interface Template {
    id: 'restart' | 'backup';
    icon: LucideIcon;
    name: string;
    desc: string;
    cron: Cron;
    onlyWhenOnline: boolean;
    task: TaskInput;
}

const daily = (hour: number): Cron => ({ minute: '0', hour: String(hour), dayOfMonth: '*', month: '*', dayOfWeek: '*' });

/**
 * Starting points for an empty Schedules page. "Run tasks on a cron
 * timetable" left players to work out cron syntax before they could do the
 * two things nearly everyone schedules. One click creates the schedule and
 * its task, then opens it so the time can be changed.
 */
export function ScheduleTemplates({ onBlank }: { onBlank: () => void }) {
    const server = useServer();
    const navigate = useNavigate();
    const push = useFlashes(s => s.push);

    const templates: Template[] = [
        {
            id: 'restart',
            icon: RotateCcw,
            name: m['server.schedules.templates.restartName'](),
            desc: m['server.schedules.templates.restartDesc'](),
            cron: daily(4),
            // Restarting a stopped server would start it.
            onlyWhenOnline: true,
            task: { action: 'power', payload: 'restart', timeOffset: 0, continueOnFailure: false },
        },
        // A backup task is refused when the plan allows no backups.
        ...(server.featureLimits.backups > 0
            ? [
                  {
                      id: 'backup' as const,
                      icon: Archive,
                      name: m['server.schedules.templates.backupName'](),
                      desc: m['server.schedules.templates.backupDesc'](),
                      cron: daily(3),
                      onlyWhenOnline: false,
                      task: { action: 'backup', payload: '', timeOffset: 0, continueOnFailure: false },
                  },
              ]
            : []),
    ];

    const create = useMutation({
        mutationFn: async (t: Template) => {
            const schedule = await saveSchedule(server.uuid, {
                name: t.name,
                cron: t.cron,
                isActive: true,
                onlyWhenOnline: t.onlyWhenOnline,
            });
            try {
                await saveTask(server.uuid, schedule.id, t.task);
            } catch (err) {
                // The schedule exists; open it so the task can be added by hand.
                push({ type: 'error', message: firstError(err) ?? m['common.states.genericError']() });
            }
            return schedule;
        },
        onSuccess: schedule => {
            push({ type: 'success', message: m['server.schedules.created']() });
            navigate(`${schedule.id}`);
        },
        onError: err => push({ type: 'error', message: firstError(err) ?? m['common.states.genericError']() }),
    });

    const card =
        'flex items-start gap-3 rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface-2)]/40 p-4 text-left transition-colors hover:border-[var(--brand)]/50 hover:bg-[var(--color-surface-2)] disabled:cursor-wait disabled:opacity-60';

    return (
        <div className="rounded-[var(--radius-card)] border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-5 py-8">
            <div className="mx-auto max-w-2xl text-center">
                <p className="text-sm font-medium text-[var(--color-ink)]">{m['server.schedules.templates.title']()}</p>
                <p className="mt-1 text-sm text-[var(--color-ink-muted)]">{m['server.schedules.templates.body']()}</p>
            </div>
            <div className="mx-auto mt-6 grid max-w-3xl gap-3 sm:grid-cols-3">
                {templates.map(t => (
                    <button key={t.id} type="button" className={card} disabled={create.isPending} onClick={() => create.mutate(t)}>
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--brand)]/12 text-[var(--brand)]">
                            {create.isPending && create.variables?.id === t.id ? <Spinner className="h-4 w-4" /> : <t.icon className="h-4 w-4" />}
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium text-[var(--color-ink)]">{t.name}</span>
                            <span className="mt-0.5 block text-xs text-[var(--color-ink-muted)]">{t.desc}</span>
                        </span>
                    </button>
                ))}
                <button type="button" className={card} disabled={create.isPending} onClick={onBlank}>
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[var(--color-surface-2)] text-[var(--color-ink-muted)]">
                        <CalendarPlus className="h-4 w-4" />
                    </span>
                    <span className="min-w-0">
                        <span className="block text-sm font-medium text-[var(--color-ink)]">{m['server.schedules.templates.blankName']()}</span>
                        <span className="mt-0.5 block text-xs text-[var(--color-ink-muted)]">{m['server.schedules.templates.blankDesc']()}</span>
                    </span>
                </button>
            </div>
        </div>
    );
}
