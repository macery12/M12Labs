import { useRef, type CSSProperties, type ReactNode } from 'react';
import { useVirtualizer } from '@tanstack/react-virtual';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';

export interface DataTableColumn<Row> {
    id: string;
    header: ReactNode;
    cell: (row: Row, index: number) => ReactNode;
    width?: number | string;
    align?: 'left' | 'center' | 'right';
    headerClassName?: string;
    cellClassName?: string | ((row: Row) => string | undefined);
}

export interface DataTablePagination {
    /** One-based current page. */
    page: number;
    pageSize: number;
    onPageChange: (page: number) => void;
    /**
     * Set for server-side pagination. When omitted, rows are sliced locally.
     * With a total, `rows` must contain only the current page.
     */
    totalItems?: number;
    previousLabel?: string;
    nextLabel?: string;
    pageLabel?: (page: number, pageCount: number) => string;
    showWhenSinglePage?: boolean;
}

export interface DataTableProps<Row> {
    columns: ReadonlyArray<DataTableColumn<Row>>;
    rows: ReadonlyArray<Row>;
    rowKey: (row: Row, index: number) => string | number;
    loading?: boolean;
    loadingLabel?: string;
    empty?: ReactNode;
    className?: string;
    tableClassName?: string;
    rowClassName?: string | ((row: Row) => string | undefined);
    pagination?: DataTablePagination;
    virtualize?: boolean;
    estimateRowHeight?: number;
    overscan?: number;
    maxHeight?: number | string;
    stickyHeader?: boolean;
    ariaLabel?: string;
}

export interface PaginationState {
    page: number;
    pageCount: number;
    start: number;
    end: number;
    totalItems: number;
}

export function paginationState(
    rowCount: number,
    { page, pageSize, totalItems }: Pick<DataTablePagination, 'page' | 'pageSize' | 'totalItems'>,
): PaginationState {
    const safePageSize = Number.isFinite(pageSize) ? Math.max(1, Math.floor(pageSize)) : 1;
    const requestedTotal = totalItems ?? rowCount;
    const total = Number.isFinite(requestedTotal) ? Math.max(0, Math.floor(requestedTotal)) : 0;
    const pageCount = Math.max(1, Math.ceil(total / safePageSize));
    const safePage = Number.isFinite(page)
        ? Math.min(pageCount, Math.max(1, Math.floor(page)))
        : 1;

    return {
        page: safePage,
        pageCount,
        start: (safePage - 1) * safePageSize,
        end: safePage * safePageSize,
        totalItems: total,
    };
}

function alignmentClass(alignment: DataTableColumn<unknown>['align']): string {
    if (alignment === 'center') return 'text-center';
    if (alignment === 'right') return 'text-right';

    return 'text-left';
}

function widthStyle(width: number | string | undefined): CSSProperties | undefined {
    if (width === undefined) return undefined;

    return { width };
}

/**
 * Typed extension table with optional controlled pagination and virtualization.
 *
 * Pagination is local unless `totalItems` is supplied. Virtualization is
 * opt-in because short tables should retain native browser layout and search;
 * when enabled, the scroll viewport is bounded by `maxHeight`.
 */
export function DataTable<Row>({
    columns,
    rows,
    rowKey,
    loading = false,
    loadingLabel = 'Loading',
    empty = 'No records found.',
    className,
    tableClassName,
    rowClassName,
    pagination,
    virtualize = false,
    estimateRowHeight = 36,
    overscan = 8,
    maxHeight = virtualize ? 480 : undefined,
    stickyHeader = virtualize,
    ariaLabel,
}: DataTableProps<Row>) {
    const scrollRef = useRef<HTMLDivElement>(null);
    const pageState = pagination ? paginationState(rows.length, pagination) : null;
    const visibleRows = pagination && pagination.totalItems === undefined && pageState
        ? rows.slice(pageState.start, pageState.end)
        : rows;

    const safeEstimate = Number.isFinite(estimateRowHeight) ? Math.max(1, estimateRowHeight) : 36;
    const safeOverscan = Number.isFinite(overscan) ? Math.max(0, Math.floor(overscan)) : 8;

    // eslint-disable-next-line react-hooks/incompatible-library -- the SDK deliberately wraps TanStack's virtualizer behind a stable component contract
    const rowVirtualizer = useVirtualizer({
        count: virtualize ? visibleRows.length : 0,
        getScrollElement: () => scrollRef.current,
        estimateSize: () => safeEstimate,
        overscan: safeOverscan,
    });
    const virtualRows = virtualize ? rowVirtualizer.getVirtualItems() : [];
    const firstVirtualRow = virtualRows[0];
    const lastVirtualRow = virtualRows[virtualRows.length - 1];
    const paddingTop = firstVirtualRow?.start ?? 0;
    const paddingBottom = lastVirtualRow ? rowVirtualizer.getTotalSize() - lastVirtualRow.end : 0;
    const tableRows = virtualize
        ? virtualRows.flatMap(virtualRow => {
              const row = visibleRows[virtualRow.index];
              return row === undefined ? [] : [{ row, index: virtualRow.index }];
          })
        : visibleRows.map((row, index) => ({ row, index }));

    const resolveRowClass = (row: Row): string | undefined =>
        typeof rowClassName === 'function' ? rowClassName(row) : rowClassName;

    return (
        <div className={cn('min-w-0', className)}>
            <div
                ref={scrollRef}
                className="overflow-auto"
                style={maxHeight === undefined ? undefined : { maxHeight }}
            >
                <table aria-label={ariaLabel} className={cn('w-full border-collapse text-xs', tableClassName)}>
                    <colgroup>
                        {columns.map(column => <col key={column.id} style={widthStyle(column.width)} />)}
                    </colgroup>
                    <thead className={cn(stickyHeader && 'sticky top-0 z-10 bg-[var(--color-surface)]')}>
                        <tr className="border-b border-[var(--color-border)] text-[var(--color-ink-faint)]">
                            {columns.map(column => (
                                <th
                                    key={column.id}
                                    scope="col"
                                    className={cn(
                                        'whitespace-nowrap px-3 py-2 font-normal',
                                        alignmentClass(column.align),
                                        column.headerClassName,
                                    )}
                                >
                                    {column.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            <tr>
                                <td colSpan={columns.length} className="px-4 py-8 text-center">
                                    <span className="inline-flex items-center gap-2 text-[var(--color-ink-faint)]">
                                        <Spinner className="h-5 w-5" />
                                        <span className="sr-only">{loadingLabel}</span>
                                    </span>
                                </td>
                            </tr>
                        ) : visibleRows.length === 0 ? (
                            <tr>
                                <td colSpan={columns.length} className="px-4 py-8 text-center text-[var(--color-ink-faint)]">
                                    {empty}
                                </td>
                            </tr>
                        ) : (
                            <>
                                {paddingTop > 0 && (
                                    <tr aria-hidden="true">
                                        <td colSpan={columns.length} style={{ height: paddingTop, padding: 0 }} />
                                    </tr>
                                )}
                                {tableRows.map(({ row, index }) => (
                                    <tr
                                        key={rowKey(row, index)}
                                        data-index={virtualize ? index : undefined}
                                        ref={virtualize ? rowVirtualizer.measureElement : undefined}
                                        className={cn(
                                            'border-b border-[var(--color-border)]/40 last:border-0 hover:bg-[var(--color-surface-2)]/50',
                                            resolveRowClass(row),
                                        )}
                                    >
                                        {columns.map(column => (
                                            <td
                                                key={column.id}
                                                className={cn(
                                                    'px-3 py-1.5',
                                                    alignmentClass(column.align),
                                                    typeof column.cellClassName === 'function'
                                                        ? column.cellClassName(row)
                                                        : column.cellClassName,
                                                )}
                                            >
                                                {column.cell(row, index)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                                {paddingBottom > 0 && (
                                    <tr aria-hidden="true">
                                        <td colSpan={columns.length} style={{ height: paddingBottom, padding: 0 }} />
                                    </tr>
                                )}
                            </>
                        )}
                    </tbody>
                </table>
            </div>

            {pagination && pageState && (pageState.pageCount > 1 || pagination.showWhenSinglePage) && (
                <div className="flex items-center justify-between gap-3 border-t border-[var(--color-border)] px-3 py-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={pageState.page <= 1}
                        onClick={() => pagination.onPageChange(pageState.page - 1)}
                    >
                        <ChevronLeft className="h-4 w-4" />
                        {pagination.previousLabel ?? 'Previous'}
                    </Button>
                    <span className="text-xs tabular-nums text-[var(--color-ink-faint)]">
                        {(pagination.pageLabel ?? ((page, count) => `Page ${page} of ${count}`))(
                            pageState.page,
                            pageState.pageCount,
                        )}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        disabled={pageState.page >= pageState.pageCount}
                        onClick={() => pagination.onPageChange(pageState.page + 1)}
                    >
                        {pagination.nextLabel ?? 'Next'}
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                </div>
            )}
        </div>
    );
}
