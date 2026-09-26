import { forwardRef, useId } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/cn';

const button = cva(
    'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors duration-150 disabled:pointer-events-none disabled:opacity-50 select-none',
    {
        variants: {
            variant: {
                primary: 'bg-[var(--brand)] text-[var(--color-brand-ink)] hover:bg-[var(--brand-hover)]',
                secondary: 'bg-[var(--color-surface-2)] text-[var(--color-ink)] hover:bg-[var(--color-border-strong)]',
                ghost: 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] hover:bg-[var(--color-surface-2)]',
                outline: 'border border-[var(--color-border-strong)] text-[var(--color-ink)] hover:bg-[var(--color-surface-2)]',
                danger: 'bg-[var(--color-danger)] text-white hover:opacity-90',
            },
            size: {
                sm: 'h-9 px-3 text-sm',
                md: 'h-11 px-5 text-sm',
                lg: 'h-12 px-7 text-base',
                icon: 'h-10 w-10',
            },
        },
        defaultVariants: { variant: 'primary', size: 'md' },
    },
);

export interface ButtonProps
    extends React.ButtonHTMLAttributes<HTMLButtonElement>,
        VariantProps<typeof button> {
    /**
     * Why the button can't be used right now. Passing one disables the button
     * and says why, both in a line under it and as a hover tooltip. A bare
     * disabled button explains nothing, and it can't show a tooltip because
     * disabled elements get no pointer events, so the wrapper carries it.
     */
    disabledReason?: string | null;
    /** Where the reason sits under the button; match the button's alignment. */
    reasonAlign?: 'start' | 'center' | 'end';
}

const reasonAlignClass = {
    start: 'items-start text-left',
    center: 'items-center text-center',
    end: 'items-end text-right',
} as const;

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
    ({ className, variant, size, disabled, disabledReason, reasonAlign = 'start', ...props }, ref) => {
        const reasonId = useId();
        const element = (
            <button
                ref={ref}
                className={cn(button({ variant, size }), className)}
                disabled={disabled || Boolean(disabledReason)}
                {...props}
                aria-describedby={disabledReason ? reasonId : props['aria-describedby']}
            />
        );
        if (!disabledReason) return element;

        // A full-width button needs a full-width wrapper to stay full width.
        const block = /(^|\s)w-full(\s|$)/.test(className ?? '');
        return (
            <span
                title={disabledReason}
                className={cn(block ? 'flex w-full' : 'inline-flex', 'flex-col gap-1', reasonAlignClass[reasonAlign])}
            >
                {element}
                <span id={reasonId} className="text-xs text-[var(--color-ink-muted)]">
                    {disabledReason}
                </span>
            </span>
        );
    },
);
Button.displayName = 'Button';
