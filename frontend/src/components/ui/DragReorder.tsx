import { useState, type DragEvent, type KeyboardEvent } from 'react';
import { ChevronDown, ChevronUp, GripVertical } from 'lucide-react';
import { cn } from '@/lib/cn';

/**
 * Drag-to-reorder for short admin lists (links, navigation). Native HTML5 drag
 * and drop, no library: the grip is the drag source and each row is a drop
 * target. The grip also moves its row with the arrow keys, and touch screens,
 * where HTML5 drag is unreliable, get large up/down buttons instead.
 *
 * `onMove(from, to)` receives indexes into the list as rendered.
 */
export function useDragReorder(onMove: (from: number, to: number) => void) {
    const [dragIndex, setDragIndex] = useState<number | null>(null);
    const [overIndex, setOverIndex] = useState<number | null>(null);

    const reset = () => {
        setDragIndex(null);
        setOverIndex(null);
    };

    return {
        dragIndex,
        overIndex,
        handleProps: (index: number, count: number) => ({
            draggable: true,
            onDragStart: (e: DragEvent<HTMLElement>) => {
                setDragIndex(index);
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', String(index));
                // Drag the whole row, not just the grip.
                const row = e.currentTarget.closest<HTMLElement>('[data-reorder-row]');
                if (row) e.dataTransfer.setDragImage(row, 16, row.offsetHeight / 2);
            },
            onDragEnd: reset,
            onKeyDown: (e: KeyboardEvent<HTMLElement>) => {
                if (e.key === 'ArrowUp' && index > 0) {
                    e.preventDefault();
                    onMove(index, index - 1);
                } else if (e.key === 'ArrowDown' && index < count - 1) {
                    e.preventDefault();
                    onMove(index, index + 1);
                }
            },
        }),
        rowProps: (index: number) => ({
            'data-reorder-row': true,
            onDragOver: (e: DragEvent<HTMLElement>) => {
                if (dragIndex === null) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                if (overIndex !== index) setOverIndex(index);
            },
            onDrop: (e: DragEvent<HTMLElement>) => {
                e.preventDefault();
                if (dragIndex !== null && dragIndex !== index) onMove(dragIndex, index);
                reset();
            },
        }),
    };
}

/**
 * The reorder control for one row. `label` names the row for screen readers
 * ("Reorder Discord"); `hint` says how to use it.
 */
export function DragHandle({
    label,
    hint,
    upLabel,
    downLabel,
    index,
    count,
    disabled,
    handleProps,
    onMove,
}: {
    label: string;
    hint: string;
    upLabel: string;
    downLabel: string;
    index: number;
    count: number;
    disabled?: boolean;
    handleProps: ReturnType<ReturnType<typeof useDragReorder>['handleProps']>;
    onMove: (from: number, to: number) => void;
}) {
    const touchButton =
        'flex h-8 w-8 items-center justify-center rounded-md text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-2)] disabled:opacity-30';

    return (
        <>
            {/* A span, not a <button>: Firefox never starts a drag from a button. */}
            <span
                role="button"
                tabIndex={disabled ? -1 : 0}
                aria-label={label}
                aria-disabled={disabled || undefined}
                title={hint}
                {...(disabled ? {} : handleProps)}
                className={cn(
                    'hidden h-8 w-8 shrink-0 items-center justify-center rounded-md text-[var(--color-ink-faint)] outline-none transition-colors focus-visible:ring-2 focus-visible:ring-[var(--color-focus-ring)] [@media(pointer:fine)]:flex',
                    disabled
                        ? 'opacity-30'
                        : 'cursor-grab hover:bg-[var(--color-surface-2)] hover:text-[var(--color-ink)] active:cursor-grabbing',
                )}
            >
                <GripVertical className="h-4 w-4" />
            </span>
            <div className="flex shrink-0 [@media(pointer:fine)]:hidden">
                <button
                    type="button"
                    aria-label={upLabel}
                    disabled={disabled || index === 0}
                    onClick={() => onMove(index, index - 1)}
                    className={touchButton}
                >
                    <ChevronUp className="h-4 w-4" />
                </button>
                <button
                    type="button"
                    aria-label={downLabel}
                    disabled={disabled || index === count - 1}
                    onClick={() => onMove(index, index + 1)}
                    className={touchButton}
                >
                    <ChevronDown className="h-4 w-4" />
                </button>
            </div>
        </>
    );
}

/**
 * Drop-position line for a row being dragged over: above it when the dragged
 * row comes from below (it will land before this one), below it otherwise.
 */
export function dropIndicator(dragIndex: number | null, overIndex: number | null, index: number): string {
    if (dragIndex === null || overIndex !== index || dragIndex === index) return '';
    return cn(
        dragIndex > index ? 'shadow-[inset_0_2px_0_0_var(--brand)]' : 'shadow-[inset_0_-2px_0_0_var(--brand)]',
    );
}
