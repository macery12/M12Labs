import { Link } from 'react-router-dom';
import { AlertTriangle } from 'lucide-react';
import { m } from '@/i18n/messages';

export interface ContentIssue {
    key: string;
    message: string;
    /** Where the fix lives outside this editor (plan names live in Products). */
    to?: string;
    /** Or jump to the section inside this editor. */
    onFix?: () => void;
}

/**
 * "Check before publishing" list for the landing and store editors: filler
 * copy and duplicate plan names found by lib/contentChecks. Warnings only; the
 * editors still save (D5).
 */
export function ContentIssues({ issues }: { issues: ContentIssue[] }) {
    if (issues.length === 0) return null;
    const fixClass = 'text-xs font-medium text-[var(--brand-bright)] hover:underline';

    return (
        <div className="rounded-lg border border-[var(--color-warning)]/40 bg-[var(--color-warning)]/10 p-4">
            <p className="flex items-center gap-2 text-sm font-semibold text-[var(--color-warning)]">
                <AlertTriangle className="h-4 w-4" />
                {m['common.contentChecks.title']()}
            </p>
            <ul className="mt-2 flex flex-col gap-1.5 text-sm text-[var(--color-ink)]">
                {issues.map(issue => (
                    <li key={issue.key} className="flex flex-wrap items-baseline gap-x-2">
                        <span>{issue.message}</span>
                        {issue.to ? (
                            <Link to={issue.to} className={fixClass}>
                                {m['common.contentChecks.fix']()}
                            </Link>
                        ) : issue.onFix ? (
                            <button type="button" onClick={issue.onFix} className={fixClass}>
                                {m['common.contentChecks.fix']()}
                            </button>
                        ) : null}
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-xs text-[var(--color-ink-muted)]">{m['common.contentChecks.note']()}</p>
        </div>
    );
}
