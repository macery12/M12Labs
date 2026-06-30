import { m, td } from '@/i18n';
import { Construction } from 'lucide-react';

// Placeholder for billing sub-pages that are routable + in the sidebar but not
// yet built (Orders, Invoices, Coupons, …). Kept theme-driven and i18n-clean.
export function ComingSoon({ titleKey }: { titleKey: string }) {
    return (
        <div className="flex min-h-[40vh] flex-col items-center justify-center gap-3 rounded-[var(--radius-card)] border border-dashed border-[var(--color-border-strong)] bg-[var(--color-surface)]/40 px-6 py-12 text-center">
            <Construction className="h-8 w-8 text-[var(--color-ink-faint)]" />
            <h2 className="text-lg font-semibold text-[var(--color-ink)]">{td(`admin.${titleKey}`)}</h2>
            <p className="max-w-sm text-sm text-[var(--color-ink-muted)]">{m['admin.billing.common.comingSoon']()}</p>
        </div>
    );
}
