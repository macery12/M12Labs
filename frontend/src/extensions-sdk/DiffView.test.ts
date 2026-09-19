import { describe, expect, it } from 'vitest';
import { createLineDiff, foldDiffRows } from './DiffView';

describe('createLineDiff', () => {
    it('keeps line numbers aligned around additions and removals', () => {
        expect(createLineDiff('one\ntwo\nthree', 'one\nchanged\nthree')).toEqual([
            { type: 'same', text: 'one', oldNumber: 1, newNumber: 1 },
            { type: 'remove', text: 'two', oldNumber: 2, newNumber: null },
            { type: 'add', text: 'changed', oldNumber: null, newNumber: 2 },
            { type: 'same', text: 'three', oldNumber: 3, newNumber: 3 },
        ]);
    });

    it('falls back to a bounded replacement for a large changed middle', () => {
        expect(createLineDiff('a\nb', 'x\ny', 1).map(row => row.type)).toEqual([
            'remove',
            'remove',
            'add',
            'add',
        ]);
    });

    it('keeps a non-finite caller limit inside the hard comparison bound', () => {
        const before = Array.from({ length: 1501 }, (_, index) => `before-${index}`).join('\n');
        const after = Array.from({ length: 1501 }, (_, index) => `after-${index}`).join('\n');
        const rows = createLineDiff(before, after, Number.POSITIVE_INFINITY);

        expect(rows).toHaveLength(3002);
        expect(rows[0]?.type).toBe('remove');
        expect(rows.at(-1)?.type).toBe('add');
    });
});

describe('foldDiffRows', () => {
    it('keeps the requested context and folds distant unchanged runs', () => {
        const rows = createLineDiff('a\nb\nc\nd\ne\nf\ng', 'a\nb\nc\nchanged\ne\nf\ng');
        const folded = foldDiffRows(rows, 1);

        expect(folded[0]).toEqual({ type: 'gap', count: 2 });
        expect(folded.at(-1)).toEqual({ type: 'gap', count: 2 });
        expect(folded.filter(row => row.type === 'gap')).toHaveLength(2);
    });
});
