import { Plus, Trash2 } from 'lucide-react';
import { m } from '@/i18n/messages';
import { cn } from '@/lib/cn';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import type { DockerRow } from '@/api/adminNests';

// Compact editor for an egg's docker image → alias pairs. The egg editor owns
// the row state; this is a controlled list. The first row with an image is the
// default a new server gets, so it is badged as such.
export function DockerImageManager({
    rows,
    onChange,
}: {
    rows: DockerRow[];
    onChange: (rows: DockerRow[]) => void;
}) {
    const setRow = (index: number, patch: Partial<DockerRow>) => {
        const next = rows.map((row, i) => (i === index ? { ...row, ...patch } : row));
        onChange(next);
    };
    const defaultIndex = rows.findIndex(row => row.image.trim() !== '');

    return (
        <div className="flex flex-col gap-3">
            <div className="hidden grid-cols-[minmax(0,1fr)_12rem_2.25rem] gap-2 px-1 text-xs font-medium uppercase tracking-wider text-[var(--color-ink-faint)] sm:grid">
                <span>{m['admin.nests.egg.docker.imageUrl']()}</span>
                <span>{m['admin.nests.egg.docker.label']()}</span>
                <span />
            </div>
            <ul className="flex flex-col gap-2">
                {rows.map((row, index) => (
                    <li key={index} className="grid grid-cols-[minmax(0,1fr)_2.25rem] gap-2 sm:grid-cols-[minmax(0,1fr)_12rem_2.25rem]">
                        <div className="relative min-w-0">
                            <Input
                                className={cn('h-9 font-mono text-xs', index === defaultIndex && 'pr-20')}
                                placeholder="ghcr.io/pterodactyl/yolks:java_17"
                                aria-label={m['admin.nests.egg.docker.imageUrl']()}
                                value={row.image}
                                onChange={e => setRow(index, { image: e.currentTarget.value })}
                            />
                            {index === defaultIndex && (
                                <span className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded border border-[var(--color-accent)]/40 bg-[var(--color-accent)]/10 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-[var(--color-accent)]">
                                    {m['admin.nests.egg.docker.default']()}
                                </span>
                            )}
                        </div>
                        <Input
                            className="order-3 col-span-2 h-9 sm:order-none sm:col-span-1"
                            placeholder="java_17"
                            aria-label={m['admin.nests.egg.docker.label']()}
                            value={row.alias}
                            onChange={e => setRow(index, { alias: e.currentTarget.value })}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9 text-[var(--color-ink-faint)] hover:text-[var(--color-danger)]"
                            disabled={rows.length < 2}
                            aria-label={m['common.actions.delete']()}
                            title={m['common.actions.delete']()}
                            onClick={() => onChange(rows.filter((_, i) => i !== index))}
                        >
                            <Trash2 className="h-4 w-4" />
                        </Button>
                    </li>
                ))}
            </ul>

            <div className="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange([...rows, { image: '', alias: '' }])}
                >
                    <Plus className="h-4 w-4" /> {m['admin.nests.egg.docker.addImage']()}
                </Button>
                <p className="text-xs text-[var(--color-ink-faint)]">{m['admin.nests.egg.docker.hint']()}</p>
            </div>
        </div>
    );
}
