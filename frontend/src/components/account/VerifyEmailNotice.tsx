import { m } from '@/i18n/messages';
import { useMutation } from '@tanstack/react-query';
import { MailWarning, Send } from 'lucide-react';
import { sendVerificationEmail } from '@/api/account';
import { firstError } from '@/lib/apiError';
import { useSession } from '@/state/session';
import { cn } from '@/lib/cn';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';

/**
 * Explains an email-verification gate and offers the resend in place, so the
 * user never has to hunt for it in Settings. `block` replaces a page's content;
 * `inline` is a strip above content the user can see but not act on.
 */
export function VerifyEmailNotice({
    title,
    body,
    variant = 'block',
    className,
}: {
    title: string;
    body?: string;
    variant?: 'block' | 'inline';
    className?: string;
}) {
    const email = useSession(s => s.user?.email ?? '');
    const resend = useMutation({ mutationFn: sendVerificationEmail });

    const status = resend.isSuccess ? (
        <p className="text-xs text-[var(--color-accent)]">{m['account.verifyEmail.sent']({ email })}</p>
    ) : resend.isError ? (
        <p className="text-xs text-[var(--color-danger)]">
            {firstError(resend.error) ?? m['common.states.genericError']()}
        </p>
    ) : null;

    const button = (
        <Button
            size="sm"
            variant="outline"
            onClick={() => resend.mutate()}
            disabled={resend.isPending || resend.isSuccess}
        >
            {resend.isPending ? <Spinner className="h-4 w-4" /> : <Send className="h-4 w-4" />}
            {m['account.verifyEmail.resend']()}
        </Button>
    );

    if (variant === 'inline') {
        return (
            <div
                className={cn(
                    'flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[var(--radius-card)] border border-[var(--color-warning)]/30 bg-[var(--color-warning)]/[0.06] px-4 py-2.5',
                    className,
                )}
            >
                <MailWarning className="h-4 w-4 shrink-0 text-[var(--color-warning)]" />
                <div className="min-w-0 flex-1">
                    <p className="text-sm text-[var(--color-ink)]">{title}</p>
                    {status}
                </div>
                {button}
            </div>
        );
    }

    return (
        <div className={cn('flex flex-col items-center gap-3 px-4 py-14 text-center', className)}>
            <MailWarning className="h-8 w-8 text-[var(--color-warning)]" />
            <p className="text-sm font-medium text-[var(--color-ink)]">{title}</p>
            {body && <p className="max-w-md text-sm text-[var(--color-ink-muted)]">{body}</p>}
            {button}
            {status}
        </div>
    );
}
