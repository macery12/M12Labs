import Spinner from '@/elements/Spinner';
import { Elements } from '@stripe/react-stripe-js';
import { loadStripe, Stripe } from '@stripe/stripe-js';
import { useEffect, useState } from 'react';
import PaymentForm from './PaymentForm';
import MolliePaymentForm from './MolliePaymentForm';
import PayPalRenewalForm from './PayPalRenewalForm';
import { ServerContext } from '@/state/server';
import { StripeIntent } from '@definitions/account/billing';
import { getStripeIntent, getStripeKey } from '@/api/routes/account/billing/orders/stripe';
import { useStoreState } from '@/state/hooks';
import classNames from 'classnames';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCreditCard, faCheck, faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';
import { faPaypal } from '@fortawesome/free-brands-svg-icons';
import { Alert } from '@/elements/alert';
import FlashMessageRender from '@/elements/FlashMessageRender';

type PaymentMethod = 'stripe' | 'mollie' | 'paypal';

export default ({ id, couponId, billingDays }: { id?: number; couponId?: number; billingDays?: number }) => {
    const [stripe, setStripe] = useState<Stripe | null>(null);
    const [intent, setIntent] = useState<StripeIntent | null>(null);

    const serverId = ServerContext.useStoreState(state => state.server.data!.internalId);
    const serverUuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const billing = useStoreState(state => state.everest.data!.billing);
    const { colors } = useStoreState(state => state.theme.data!);

    const stripeAvailable = billing.processors?.stripe?.available;

    // Determine available payment methods
    const availableProcessors: PaymentMethod[] = [];

    if (stripeAvailable) {
        availableProcessors.push('stripe');
    }

    if (billing.processors?.mollie?.available) {
        availableProcessors.push('mollie');
    }

    if (billing.processors?.paypal?.available) {
        availableProcessors.push('paypal');
    }

    // Default to first available processor, or undefined if none available
    const [selectedMethod, setSelectedMethod] = useState<PaymentMethod | undefined>(
        availableProcessors.length === 1 ? availableProcessors[0] : undefined,
    );

    // If only one processor is available, don't show selection UI
    const showSelection = availableProcessors.length > 1;

    useEffect(() => {
        const fetchData = async () => {
            // Only fetch Stripe data if Stripe is available and selected
            if (!id || !stripeAvailable || selectedMethod !== 'stripe') return;

            try {
                const intentData = await getStripeIntent(id, couponId);
                setIntent({ id: intentData.id, secret: intentData.secret });

                const stripePublicKey = await getStripeKey(id);
                const stripeInstance = await loadStripe(stripePublicKey.key);
                setStripe(stripeInstance);
            } catch (error) {
                console.error('Error fetching Stripe data:', error);
            }
        };

        fetchData();
    }, [id, couponId, selectedMethod, stripeAvailable]);

    if (!id) return <Spinner size={'large'} centered />;

    // If no processors are available, show error message
    if (availableProcessors.length === 0) {
        return (
            <Alert type={'danger'}>
                <div className={'flex items-start gap-3'}>
                    <FontAwesomeIcon icon={faExclamationTriangle} className={'mt-0.5 h-5 w-5'} />
                    <div>
                        <p className={'font-semibold'}>No Payment Methods Available</p>
                        <p className={'mt-1 text-sm'}>
                            No payment integrations are currently enabled. Please contact support to enable payment
                            processing for your renewal.
                        </p>
                    </div>
                </div>
            </Alert>
        );
    }

    const stripeOptions = {
        clientSecret: intent?.secret,
        appearance: {
            theme: 'night' as const,
            variables: {
                colorText: '#ffffff',
            },
        },
    };

    return (
        <div>
            <FlashMessageRender byKey={'suspended:billing'} className={'mb-4'} />

            {showSelection && (
                <div className={'mb-6'}>
                    <h4 className={'mb-3 text-sm font-semibold text-gray-200'}>Select Payment Method</h4>
                    <div className={'grid gap-3 sm:grid-cols-2'}>
                        {availableProcessors.includes('stripe') && (
                            <button
                                type={'button'}
                                onClick={() => setSelectedMethod('stripe')}
                                className={classNames(
                                    'relative flex items-center gap-3 rounded-lg border-2 p-4 text-left transition-all',
                                    'hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-2',
                                    selectedMethod === 'stripe'
                                        ? 'border-primary bg-opacity-20'
                                        : 'border-gray-600 bg-transparent',
                                )}
                                style={
                                    selectedMethod === 'stripe'
                                        ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` }
                                        : {}
                                }
                            >
                                <div
                                    className={classNames(
                                        'flex h-10 w-10 items-center justify-center rounded-lg',
                                        selectedMethod === 'stripe' ? 'text-white' : 'text-gray-400',
                                    )}
                                    style={
                                        selectedMethod === 'stripe'
                                            ? { backgroundColor: colors.primary }
                                            : { backgroundColor: colors.secondary }
                                    }
                                >
                                    <FontAwesomeIcon icon={faCreditCard} className={'h-5 w-5'} />
                                </div>
                                <div className={'flex-1'}>
                                    <div className={'flex items-center gap-2'}>
                                        <p className={'font-semibold text-gray-100'}>Stripe</p>
                                        {selectedMethod === 'stripe' && (
                                            <FontAwesomeIcon
                                                icon={faCheck}
                                                className={'h-4 w-4'}
                                                style={{ color: colors.primary }}
                                            />
                                        )}
                                    </div>
                                    <p className={'mt-0.5 text-xs text-gray-400'}>
                                        Card, PayPal{billing.link ? ', Link' : ''}
                                    </p>
                                </div>
                            </button>
                        )}

                        {availableProcessors.includes('mollie') && (
                            <button
                                type={'button'}
                                onClick={() => setSelectedMethod('mollie')}
                                className={classNames(
                                    'relative flex items-center gap-3 rounded-lg border-2 p-4 text-left transition-all',
                                    'hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-2',
                                    selectedMethod === 'mollie'
                                        ? 'border-primary bg-opacity-20'
                                        : 'border-gray-600 bg-transparent',
                                )}
                                style={
                                    selectedMethod === 'mollie'
                                        ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` }
                                        : {}
                                }
                            >
                                <div
                                    className={classNames(
                                        'flex h-10 w-10 items-center justify-center rounded-lg',
                                        selectedMethod === 'mollie' ? 'text-white' : 'text-gray-400',
                                    )}
                                    style={
                                        selectedMethod === 'mollie'
                                            ? { backgroundColor: colors.primary }
                                            : { backgroundColor: colors.secondary }
                                    }
                                >
                                    <FontAwesomeIcon icon={faCreditCard} className={'h-5 w-5'} />
                                </div>
                                <div className={'flex-1'}>
                                    <div className={'flex items-center gap-2'}>
                                        <p className={'font-semibold text-gray-100'}>Mollie</p>
                                        {selectedMethod === 'mollie' && (
                                            <FontAwesomeIcon
                                                icon={faCheck}
                                                className={'h-4 w-4'}
                                                style={{ color: colors.primary }}
                                            />
                                        )}
                                    </div>
                                    <p className={'mt-0.5 text-xs text-gray-400'}>Card, iDEAL, and more</p>
                                </div>
                            </button>
                        )}

                        {availableProcessors.includes('paypal') && (
                            <button
                                type={'button'}
                                onClick={() => setSelectedMethod('paypal')}
                                className={classNames(
                                    'relative flex items-center gap-3 rounded-lg border-2 p-4 text-left transition-all',
                                    'hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-2',
                                    selectedMethod === 'paypal'
                                        ? 'border-primary bg-opacity-20'
                                        : 'border-gray-600 bg-transparent',
                                )}
                                style={
                                    selectedMethod === 'paypal'
                                        ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` }
                                        : {}
                                }
                            >
                                <div
                                    className={classNames(
                                        'flex h-10 w-10 items-center justify-center rounded-lg',
                                        selectedMethod === 'paypal' ? 'text-white' : 'text-gray-400',
                                    )}
                                    style={
                                        selectedMethod === 'paypal'
                                            ? { backgroundColor: colors.primary }
                                            : { backgroundColor: colors.secondary }
                                    }
                                >
                                    <FontAwesomeIcon icon={faPaypal} className={'h-5 w-5'} />
                                </div>
                                <div className={'flex-1'}>
                                    <div className={'flex items-center gap-2'}>
                                        <p className={'font-semibold text-gray-100'}>PayPal</p>
                                        {selectedMethod === 'paypal' && (
                                            <FontAwesomeIcon
                                                icon={faCheck}
                                                className={'h-4 w-4'}
                                                style={{ color: colors.primary }}
                                            />
                                        )}
                                    </div>
                                    <p className={'mt-0.5 text-xs text-gray-400'}>PayPal account or card</p>
                                </div>
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* Render the selected payment method */}
            {selectedMethod === 'stripe' ? (
                <>
                    {!intent || !stripe ? (
                        <Spinner size={'large'} centered />
                    ) : (
                        <div>
                            {/* @ts-expect-error this is fine, stripe library is just weird */}
                            {/* Key prop forces re-mount when intent changes (e.g., coupon applied/removed) */}
                            <Elements stripe={stripe} options={stripeOptions} key={intent.id}>
                                <PaymentForm
                                    id={id}
                                    serverId={Number(serverId)}
                                    serverUuid={serverUuid}
                                    intent={intent.id}
                                    renewal
                                    billingDays={billingDays}
                                />
                            </Elements>
                        </div>
                    )}
                </>
            ) : selectedMethod === 'mollie' ? (
                <div>
                    <MolliePaymentForm
                        id={id}
                        serverId={Number(serverId)}
                        serverUuid={serverUuid}
                        couponId={couponId}
                    />
                </div>
            ) : selectedMethod === 'paypal' ? (
                <div>
                    <PayPalRenewalForm
                        id={id}
                        serverId={Number(serverId)}
                        serverUuid={serverUuid}
                        couponId={couponId}
                    />
                </div>
            ) : null}
        </div>
    );
};
