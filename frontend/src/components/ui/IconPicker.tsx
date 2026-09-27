import { Ban } from 'lucide-react';
import { m } from '@/i18n/messages';
import { cn } from '@/lib/cn';
import { LANDING_ICON_NAMES, resolveIcon } from '@/pages/landing/sections/icons';

/**
 * Compact visual icon grid, limited to the shared Lucide allowlist so stored
 * config never references an arbitrary component. Used by the landing and
 * store editors and the billing category editor. `allowNone` adds a "no icon"
 * choice that sets the value to ''.
 */
export function IconPicker({
    value,
    onChange,
    allowNone,
}: {
    value: string;
    onChange: (v: string) => void;
    allowNone?: boolean;
}) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {allowNone && (
                <IconChoice label={m['common.states.noIcon']()} active={value === ''} onClick={() => onChange('')}>
                    <Ban className="h-4 w-4" />
                </IconChoice>
            )}
            {LANDING_ICON_NAMES.map(n => {
                const Icon = resolveIcon(n);
                return (
                    <IconChoice key={n} label={n} active={n === value} onClick={() => onChange(n)}>
                        <Icon className="h-4 w-4" />
                    </IconChoice>
                );
            })}
        </div>
    );
}

function IconChoice({
    label,
    active,
    onClick,
    children,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'flex h-9 w-9 items-center justify-center rounded-lg border transition-colors',
                active
                    ? 'border-[var(--brand)] bg-[var(--brand-soft)] text-[var(--brand)]'
                    : 'border-[var(--color-border)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-2)]',
            )}
        >
            {children}
        </button>
    );
}
