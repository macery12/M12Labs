import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { DataTable, paginationState, type DataTableColumn } from './DataTable';

describe('paginationState', () => {
    it('uses local row count and one-based pages', () => {
        expect(paginationState(25, { page: 2, pageSize: 10 })).toEqual({
            page: 2,
            pageCount: 3,
            start: 10,
            end: 20,
            totalItems: 25,
        });
    });

    it('uses the server total and clamps an invalid page', () => {
        expect(paginationState(10, { page: 99, pageSize: 10, totalItems: 42 })).toEqual({
            page: 5,
            pageCount: 5,
            start: 40,
            end: 50,
            totalItems: 42,
        });
    });

    it('keeps an empty table on page one', () => {
        expect(paginationState(0, { page: 0, pageSize: 0 })).toEqual({
            page: 1,
            pageCount: 1,
            start: 0,
            end: 1,
            totalItems: 0,
        });
    });

    it('normalizes non-finite input instead of leaking NaN into controls', () => {
        expect(paginationState(10, { page: Number.NaN, pageSize: Number.POSITIVE_INFINITY })).toEqual({
            page: 1,
            pageCount: 10,
            start: 0,
            end: 1,
            totalItems: 10,
        });
    });
});

describe('DataTable', () => {
    it('renders only the active local page', () => {
        interface Row {
            id: number;
            name: string;
        }

        const columns: DataTableColumn<Row>[] = [{ id: 'name', header: 'Name', cell: row => row.name }];
        const rows: Row[] = [
            { id: 1, name: 'first' },
            { id: 2, name: 'second' },
            { id: 3, name: 'third' },
        ];
        const TestTable = DataTable<Row>;
        const html = renderToStaticMarkup(createElement(TestTable, {
            columns,
            rows,
            rowKey: row => row.id,
            pagination: { page: 2, pageSize: 2, onPageChange: () => {} },
        }));

        expect(html).toContain('third');
        expect(html).not.toContain('first');
        expect(html).not.toContain('second');
        expect(html).toContain('Page 2 of 2');
    });
});
