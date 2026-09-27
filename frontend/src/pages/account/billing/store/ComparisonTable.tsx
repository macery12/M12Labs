import { m } from '@/i18n/messages';
import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import { useBilling } from '@/state/billing';
import type { StoreProduct } from '@/api/accountBilling';
import { cpuPercentHint, formatVcpu } from '@/lib/format';

const gb = (mib: number) => `${(mib / 1024).toFixed(mib % 1024 === 0 ? 0 : 1)} GB`;

// Side-by-side spec comparison. The label column is sticky so it stays visible
// while the plan columns scroll horizontally; rows zebra-stripe and the price
// row is emphasised.
export function ComparisonTable({ products }: { products: StoreProduct[] }) {
    const { money } = useBilling();

    const rows: { label: string; value: (p: StoreProduct) => string; hint?: (p: StoreProduct) => string | undefined; emphasis?: boolean }[] = [
        { label: m['common.metrics.cpu'](), value: p => formatVcpu(p.limits.cpu), hint: p => cpuPercentHint(p.limits.cpu) },
        { label: m['billing.store.compare.memory'](), value: p => gb(p.limits.memory) },
        { label: m['ui.labels.storage'](), value: p => gb(p.limits.disk) },
        { label: m['ui.labels.backups'](), value: p => String(p.limits.backup) },
        { label: m['ui.labels.databases'](), value: p => String(p.limits.database) },
        { label: m['ui.labels.price'](), value: p => (p.price === 0 ? m['ui.labels.free']() : money(p.price)), emphasis: true },
    ];

    const stickyCol =
        'sticky left-0 z-10 w-36 min-w-[9rem] bg-[var(--color-surface-2)] px-4 py-3 text-left';
    const planCol = 'min-w-[8.5rem] px-4 py-3 text-left align-middle';

    return (
        <div className="overflow-x-auto rounded-lg border border-[var(--color-border-strong)] bg-[var(--color-surface)]/60">
            <table className="w-full border-collapse text-sm">
                <thead>
                    <tr className="border-b border-[var(--color-border)]">
                        <th className={`${stickyCol} text-[11px] font-semibold uppercase tracking-wide text-[var(--color-ink-faint)]`}>
                            {m['ui.labels.plan']()}
                        </th>
                        {products.map(p => (
                            <th key={p.id} className={`${planCol} font-semibold text-[var(--color-ink)]`}>
                                {p.name}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map(row => (
                        <tr key={row.label} className="border-b border-[var(--color-border)] last:border-0">
                            <td className={`${stickyCol} text-[var(--color-ink-muted)]`}>{row.label}</td>
                            {products.map(p => (
                                <td
                                    key={p.id}
                                    title={row.hint?.(p)}
                                    className={`${planCol} ${
                                        row.emphasis
                                            ? 'font-bold text-[var(--color-ink)]'
                                            : 'font-medium text-[var(--color-ink)]'
                                    }`}
                                >
                                    {row.value(p)}
                                </td>
                            ))}
                        </tr>
                    ))}
                    <tr>
                        <td className={`${stickyCol}`} />
                        {products.map(p => (
                            <td key={p.id} className={planCol}>
                                <Link to={`/checkout/configure/${p.id}`}>
                                    <Button size="sm" className="w-full">
                                        {m['ui.actions.configure']()}
                                    </Button>
                                </Link>
                            </td>
                        ))}
                    </tr>
                </tbody>
            </table>
        </div>
    );
}
