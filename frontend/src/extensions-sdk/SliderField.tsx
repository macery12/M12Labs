import { useId } from 'react';
import { cn } from '@/lib/cn';

export interface SliderFieldProps {
    label: string;
    value: number;
    min: number;
    max: number;
    step?: number;
    onChange: (value: number) => void;
    displayValue?: string;
    lowLabel?: string;
    highLabel?: string;
    hint?: string;
    disabled?: boolean;
    id?: string;
    className?: string;
}

/** A labelled, keyboard-native range input with optional endpoint guidance. */
export function SliderField({
    label,
    value,
    min,
    max,
    step = 1,
    onChange,
    displayValue = String(value),
    lowLabel,
    highLabel,
    hint,
    disabled,
    id,
    className,
}: SliderFieldProps) {
    const generatedId = useId();
    const inputId = id ?? generatedId;
    const hintId = hint ? `${inputId}-hint` : undefined;

    return (
        <div className={cn(disabled && 'opacity-60', className)}>
            <div className="mb-1.5 flex items-center justify-between gap-3">
                <label htmlFor={inputId} className="text-sm font-medium text-[var(--color-ink-muted)]">
                    {label}
                </label>
                <output htmlFor={inputId} className="font-mono text-xs text-[var(--brand)]">
                    {displayValue}
                </output>
            </div>
            {hint && (
                <p id={hintId} className="mb-1.5 text-xs text-[var(--color-ink-faint)]">
                    {hint}
                </p>
            )}
            <input
                id={inputId}
                type="range"
                min={min}
                max={max}
                step={step}
                value={value}
                disabled={disabled}
                aria-describedby={hintId}
                aria-valuetext={displayValue}
                onChange={event => onChange(Number(event.target.value))}
                className="h-1.5 w-full cursor-pointer appearance-none rounded-full bg-[var(--color-surface-2)] disabled:cursor-not-allowed"
                style={{ accentColor: 'var(--brand)' }}
            />
            {(lowLabel || highLabel) && (
                <div aria-hidden="true" className="mt-1 flex justify-between text-xs text-[var(--color-ink-faint)]">
                    <span>{lowLabel}</span>
                    <span>{highLabel}</span>
                </div>
            )}
        </div>
    );
}
