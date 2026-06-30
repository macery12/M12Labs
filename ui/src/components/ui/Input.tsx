import { forwardRef } from 'react';
import { cn } from '@/lib/cn';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
    invalid?: boolean;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(
    ({ className, invalid, ...props }, ref) => (
        <input
            ref={ref}
            className={cn(
                'h-11 w-full rounded-xl border bg-[var(--color-surface-2)] px-4 text-sm text-[var(--color-ink)] placeholder:text-[var(--color-ink-faint)]',
                'transition-colors focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/60',
                invalid ? 'border-[var(--color-danger)]' : 'border-[var(--color-border-strong)]',
                className,
            )}
            {...props}
        />
    ),
);
Input.displayName = 'Input';

export function Field({
    label,
    error,
    children,
    htmlFor,
}: {
    label: string;
    error?: string;
    htmlFor?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={htmlFor} className="text-sm font-medium text-[var(--color-ink-muted)]">
                {label}
            </label>
            {children}
            {error && <span className="text-xs text-[var(--color-danger)]">{error}</span>}
        </div>
    );
}
