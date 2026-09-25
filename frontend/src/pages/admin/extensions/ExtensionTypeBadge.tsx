import { td } from '@/i18n/messages';
import { cn } from '@/lib/cn';

// A manifest's `both` type used to render as one long "Admin + Server" label,
// which wrapped mid-phrase inside the narrow type column. Split it into the two
// surfaces it actually describes so each stays on a single line.
const TYPE_PARTS: Record<string, string[]> = {
    admin: ['admin'],
    user: ['user'],
    both: ['admin', 'user'],
};

export function ExtensionTypeBadge({ type, className }: { type: string; className?: string }) {
    const parts = TYPE_PARTS[type] ?? [type];
    return (
        <span className={cn('inline-flex items-center gap-1', className)}>
            {parts.map(part => (
                <span
                    key={part}
                    className="whitespace-nowrap rounded border border-[var(--color-border)] px-1.5 py-px text-[11px] font-medium text-[var(--color-ink-muted)]"
                >
                    {td(`extensions.type.${part}`)}
                </span>
            ))}
        </span>
    );
}
