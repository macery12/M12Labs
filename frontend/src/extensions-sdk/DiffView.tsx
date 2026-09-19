import { useMemo } from 'react';
import { cn } from '@/lib/cn';

export interface DiffRow {
    type: 'same' | 'add' | 'remove';
    text: string;
    oldNumber: number | null;
    newNumber: number | null;
}

export interface DiffGap {
    type: 'gap';
    count: number;
}

export interface DiffViewProps {
    original: string;
    updated: string;
    className?: string;
    contextLines?: number;
    maxComparedLines?: number;
    legend?: string;
    foldedLabel?: (count: number) => string;
}

const MAX_LCS_LINES = 1500;

/**
 * A bounded line diff. Large changed middles are shown as a replacement rather
 * than allocating an unbounded quadratic LCS table in the browser.
 */
export function createLineDiff(original: string, updated: string, maxComparedLines = 1500): DiffRow[] {
    const before = original.split('\n');
    const after = updated.split('\n');
    let start = 0;

    while (start < before.length && start < after.length && before[start] === after[start]) start++;

    let end = 0;
    while (
        end < before.length - start &&
        end < after.length - start &&
        before[before.length - 1 - end] === after[after.length - 1 - end]
    ) {
        end++;
    }

    const rows = before.slice(0, start).map<DiffRow>((text, index) => ({
        type: 'same',
        text,
        oldNumber: index + 1,
        newNumber: index + 1,
    }));
    const oldMiddle = before.slice(start, before.length - end);
    const newMiddle = after.slice(start, after.length - end);
    const requestedLimit = Number.isFinite(maxComparedLines) ? Math.max(0, Math.floor(maxComparedLines)) : MAX_LCS_LINES;
    const comparisonLimit = Math.min(MAX_LCS_LINES, requestedLimit);
    let oldNumber = start + 1;
    let newNumber = start + 1;

    if (oldMiddle.length > comparisonLimit || newMiddle.length > comparisonLimit) {
        for (const text of oldMiddle) rows.push({ type: 'remove', text, oldNumber: oldNumber++, newNumber: null });
        for (const text of newMiddle) rows.push({ type: 'add', text, oldNumber: null, newNumber: newNumber++ });
    } else {
        const width = newMiddle.length + 1;
        const table = new Uint32Array((oldMiddle.length + 1) * width);

        for (let oldIndex = oldMiddle.length - 1; oldIndex >= 0; oldIndex--) {
            for (let newIndex = newMiddle.length - 1; newIndex >= 0; newIndex--) {
                table[oldIndex * width + newIndex] =
                    oldMiddle[oldIndex] === newMiddle[newIndex]
                        ? (table[(oldIndex + 1) * width + newIndex + 1] ?? 0) + 1
                        : Math.max(
                              table[(oldIndex + 1) * width + newIndex] ?? 0,
                              table[oldIndex * width + newIndex + 1] ?? 0,
                          );
            }
        }

        let oldIndex = 0;
        let newIndex = 0;
        while (oldIndex < oldMiddle.length && newIndex < newMiddle.length) {
            const oldLine = oldMiddle[oldIndex] ?? '';
            const newLine = newMiddle[newIndex] ?? '';

            if (oldLine === newLine) {
                rows.push({ type: 'same', text: oldLine, oldNumber: oldNumber++, newNumber: newNumber++ });
                oldIndex++;
                newIndex++;
            } else if (
                (table[(oldIndex + 1) * width + newIndex] ?? 0) >=
                (table[oldIndex * width + newIndex + 1] ?? 0)
            ) {
                rows.push({ type: 'remove', text: oldLine, oldNumber: oldNumber++, newNumber: null });
                oldIndex++;
            } else {
                rows.push({ type: 'add', text: newLine, oldNumber: null, newNumber: newNumber++ });
                newIndex++;
            }
        }

        while (oldIndex < oldMiddle.length) {
            rows.push({ type: 'remove', text: oldMiddle[oldIndex++] ?? '', oldNumber: oldNumber++, newNumber: null });
        }
        while (newIndex < newMiddle.length) {
            rows.push({ type: 'add', text: newMiddle[newIndex++] ?? '', oldNumber: null, newNumber: newNumber++ });
        }
    }

    for (let index = 0; index < end; index++) {
        rows.push({
            type: 'same',
            text: before[before.length - end + index] ?? '',
            oldNumber: oldNumber++,
            newNumber: newNumber++,
        });
    }

    return rows;
}

export function foldDiffRows(rows: DiffRow[], contextLines = 3): Array<DiffRow | DiffGap> {
    const context = Number.isFinite(contextLines) ? Math.max(0, Math.floor(contextLines)) : 3;
    const keep = new Set<number>();

    rows.forEach((row, index) => {
        if (row.type === 'same') return;
        for (let nearby = index - context; nearby <= index + context; nearby++) keep.add(nearby);
    });

    const folded: Array<DiffRow | DiffGap> = [];
    let skipped = 0;
    rows.forEach((row, index) => {
        if (keep.has(index)) {
            if (skipped > 0) folded.push({ type: 'gap', count: skipped });
            skipped = 0;
            folded.push(row);
        } else {
            skipped++;
        }
    });

    if (skipped > 0) folded.push({ type: 'gap', count: skipped });

    return folded;
}

export function DiffView({
    original,
    updated,
    className,
    contextLines = 3,
    maxComparedLines = 1500,
    legend = 'Changed lines',
    foldedLabel = count => `${count} unchanged ${count === 1 ? 'line' : 'lines'}`,
}: DiffViewProps) {
    const { rows, additions, deletions } = useMemo(() => {
        const all = createLineDiff(original, updated, maxComparedLines);

        return {
            rows: foldDiffRows(all, contextLines),
            additions: all.filter(row => row.type === 'add').length,
            deletions: all.filter(row => row.type === 'remove').length,
        };
    }, [contextLines, maxComparedLines, original, updated]);

    return (
        <div className={cn('overflow-hidden rounded-md border border-[var(--color-border)]', className)}>
            <div className="flex items-center gap-3 border-b border-[var(--color-border)] bg-[var(--color-surface-2)] px-3 py-1.5 text-xs">
                <span className="font-medium text-[var(--color-accent)]">+{additions}</span>
                <span className="font-medium text-[var(--color-danger)]">−{deletions}</span>
                <span className="text-[var(--color-ink-faint)]">{legend}</span>
            </div>

            <div className="max-h-72 overflow-auto">
                <table className="w-full border-collapse font-mono text-[11px] leading-relaxed">
                    <tbody>
                        {rows.map((row, index) =>
                            row.type === 'gap' ? (
                                <tr key={`gap-${index}`}>
                                    <td
                                        colSpan={3}
                                        className="bg-[var(--color-surface-2)]/50 px-3 py-1 text-center text-[10px] text-[var(--color-ink-faint)]"
                                    >
                                        {foldedLabel(row.count)}
                                    </td>
                                </tr>
                            ) : (
                                <tr
                                    key={`${row.type}-${row.oldNumber ?? ''}-${row.newNumber ?? ''}-${index}`}
                                    className={cn(
                                        row.type === 'add' && 'bg-[var(--color-accent)]/10',
                                        row.type === 'remove' && 'bg-[var(--color-danger)]/10',
                                    )}
                                >
                                    <td className="w-10 select-none border-r border-[var(--color-border)] px-2 text-right align-top text-[var(--color-ink-faint)]">
                                        {row.oldNumber ?? ''}
                                    </td>
                                    <td className="w-10 select-none border-r border-[var(--color-border)] px-2 text-right align-top text-[var(--color-ink-faint)]">
                                        {row.newNumber ?? ''}
                                    </td>
                                    <td
                                        className={cn(
                                            'whitespace-pre-wrap break-all px-2 align-top',
                                            row.type === 'add' && 'text-[var(--color-accent)]',
                                            row.type === 'remove' && 'text-[var(--color-danger)]',
                                            row.type === 'same' && 'text-[var(--color-ink-muted)]',
                                        )}
                                    >
                                        <span className="select-none opacity-60">
                                            {row.type === 'add' ? '+' : row.type === 'remove' ? '−' : ' '}
                                        </span>{' '}
                                        {row.text || ' '}
                                    </td>
                                </tr>
                            ),
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
