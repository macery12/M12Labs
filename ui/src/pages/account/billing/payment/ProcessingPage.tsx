import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Spinner } from '@/components/ui/Spinner';
import { useFlashes } from '@/state/flashes';
import { useBilling } from '@/state/billing';
import {
    processPaidOrder,
    capturePayPalOrder,
    checkPayPalOrderStatus,
    getOrderIdFromToken,
} from '@/api/accountBilling';

// Terminal payment handler. Stripe returns here with ?payment_intent=…; PayPal
// with ?token=…&processor=paypal. We finalise the order, then route to the
// success or cancel page. Ported from V1's summary/Processing.
export default function ProcessingPage() {
    const { t } = useTranslation('billing');
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const push = useFlashes(s => s.push);
    const { billing } = useBilling();
    const [session] = useState(() => params.get('payment_intent') ?? params.get('token') ?? 'Unknown');
    const ran = useRef(false);

    useEffect(() => {
        if (ran.current) return;
        ran.current = true;

        const stripeIntent = params.get('payment_intent');
        const token = params.get('token');
        const processor = params.get('processor');

        if (stripeIntent) {
            processPaidOrder(stripeIntent)
                .then(() => navigate('/v2/account/billing/success'))
                .catch(() => navigate('/v2/account/billing/cancel'));
            return;
        }

        const paypalActive = processor === 'paypal' || (billing.processors?.paypal?.available && !processor);
        if (token && paypalActive) {
            getOrderIdFromToken(token)
                .then(({ order_id }) =>
                    capturePayPalOrder(order_id).then(() => {
                        let polls = 0;
                        const poll = async () => {
                            polls += 1;
                            if (polls > 60) {
                                push({ type: 'warning', message: t('processing.verifyDelay') });
                                return;
                            }
                            const status = await checkPayPalOrderStatus(order_id);
                            if (status.processed) navigate('/v2/account/billing/success');
                            else if (status.failed) navigate('/v2/account/billing/cancel');
                            else setTimeout(poll, 2000);
                        };
                        return poll();
                    }),
                )
                .catch(() => {
                    push({ type: 'error', message: t('processing.paypalVerifyError') });
                    navigate('/v2/account/billing/cancel');
                });
            return;
        }

        push({ type: 'error', message: t('processing.fulfillError') });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="flex min-h-[60vh] items-center justify-center">
            <div className="w-full max-w-md rounded-2xl border border-[var(--color-border-strong)] bg-[var(--color-surface)]/70 p-10 text-center">
                <Spinner className="mx-auto h-9 w-9" />
                <h2 className="mt-5 text-xl font-semibold text-[var(--color-ink)]">{t('processing.title')}</h2>
                <p className="mt-2 text-sm text-[var(--color-ink-muted)]">{t('processing.body')}</p>
                <p className="mt-6 text-[11px] text-[var(--color-ink-faint)]">
                    {t('processing.session', { id: session })}
                </p>
            </div>
        </div>
    );
}
