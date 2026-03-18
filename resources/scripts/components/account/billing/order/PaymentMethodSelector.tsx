import { useState } from 'react';
import { useStoreState } from '@/state/hooks';
import { Product, StripeIntent } from '@definitions/account/billing';
import PaymentButton from './PaymentButton';
import MolliePaymentButton from './MolliePaymentButton';
import PayPalPaymentButton from './PayPalPaymentButton';
import { Elements } from '@stripe/react-stripe-js';
import { Stripe } from '@stripe/stripe-js';
import classNames from 'classnames';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCreditCard, faCheck, faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';
import { faPaypal } from '@fortawesome/free-brands-svg-icons';
import { Alert } from '@/elements/alert';

interface Props {
    selectedNode?: number;
    product: Product;
    vars: Map<string, string>;
    intent: StripeIntent | null;
    stripe: Stripe | null;
    couponId?: number;
    selectedEggId?: number;
    serverName: string;
}

type PaymentMethod = 'stripe' | 'mollie' | 'paypal';

export default (props: Props) => {
    const { colors } = useStoreState(state => state.theme.data!);
    const billing = useStoreState(state => state.everest.data!.billing);

    const configuredProcessors: Array<{ method: PaymentMethod; available: boolean }> = [
        {
            method: 'stripe',
            available: billing.processors?.stripe?.available ?? false,
        },
        {
            method: 'mollie',
            available: billing.processors?.mollie?.available ?? false,
        },
        {
            method: 'paypal',
            available: billing.processors?.paypal?.available ?? false,
        },
    ].filter(processor => {
        if (processor.method === 'stripe') {
            return billing.processors?.stripe?.enabled;
        }

        if (processor.method === 'mollie') {
            return billing.processors?.mollie?.enabled;
        }

        return billing.processors?.paypal?.enabled;
    });

    const availableProcessors = configuredProcessors
        .filter(processor => processor.available)
        .map(processor => processor.method);

    const [selectedMethod, setSelectedMethod] = useState<PaymentMethod | undefined>(availableProcessors[0]);
    const showSelection = configuredProcessors.length > 1;

    const getProcessor = (method: PaymentMethod) => configuredProcessors.find(processor => processor.method === method);

    // If no processors are available, show error message
    if (availableProcessors.length === 0) {
        return (
            <Alert type={'danger'}>
                <div className={'flex items-start gap-3'}>
                    <FontAwesomeIcon icon={faExclamationTriangle} className={'mt-0.5 h-5 w-5'} />
                    <div>
                        <p className={'font-semibold'}>No Payment Methods Available</p>
                        <p className={'mt-1 text-sm'}>Payment system has not been setup correctly.</p>
                    </div>
                </div>
            </Alert>
        );
    }

    const stripeOptions = {
        clientSecret: props.intent?.secret,
        appearance: {
            theme: 'night' as const,
            variables: {
                colorText: '#ffffff',
            },
        },
    };

    return (
        <div>
            {showSelection && (
                <div className={'mb-6'}>
                    <h4 className={'mb-3 text-sm font-semibold text-gray-200'}>Select Payment Method</h4>
                    <div className={'grid gap-3 sm:grid-cols-2'}>
                        {getProcessor('stripe') && (
                            <button
                                type={'button'}
                                disabled={!getProcessor('stripe')?.available}
                                onClick={() => setSelectedMethod('stripe')}
                                className={classNames(
                                    'relative flex items-center gap-3 rounded-lg border-2 p-4 text-left transition-all',
                                    getProcessor('stripe')?.available
                                        ? 'hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-2'
                                        : 'cursor-not-allowed opacity-50',
                                    selectedMethod === 'stripe' ? 'border-primary' : 'border-gray-700',
                                )}
                                style={
                                    selectedMethod === 'stripe'
                                        ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` }
                                        : { backgroundColor: colors.background, borderColor: '#374151' }
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
                                    {!getProcessor('stripe')?.available && (
                                        <p className={'mt-1 text-xs text-yellow-400'}>Unavailable</p>
                                    )}
                                </div>
                            </button>
                        )}

                        {getProcessor('mollie') && (
                            <button
                                type={'button'}
                                disabled={!getProcessor('mollie')?.available}
                                onClick={() => setSelectedMethod('mollie')}
                                className={classNames(
                                    'relative flex items-center gap-3 rounded-lg border-2 p-4 text-left transition-all',
                                    getProcessor('mollie')?.available
                                        ? 'hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-2'
                                        : 'cursor-not-allowed opacity-50',
                                    selectedMethod === 'mollie' ? 'border-primary' : 'border-gray-700',
                                )}
                                style={
                                    selectedMethod === 'mollie'
                                        ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` }
                                        : { backgroundColor: colors.background, borderColor: '#374151' }
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
                                    {!getProcessor('mollie')?.available && (
                                        <p className={'mt-1 text-xs text-yellow-400'}>Unavailable</p>
                                    )}
                                </div>
                            </button>
                        )}

                        {getProcessor('paypal') && (
                            <button
                                type={'button'}
                                disabled={!getProcessor('paypal')?.available}
                                onClick={() => setSelectedMethod('paypal')}
                                className={classNames(
                                    'relative flex items-center gap-3 rounded-lg border-2 p-4 text-left transition-all',
                                    getProcessor('paypal')?.available
                                        ? 'hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-2'
                                        : 'cursor-not-allowed opacity-50',
                                    selectedMethod === 'paypal' ? 'border-primary' : 'border-gray-700',
                                )}
                                style={
                                    selectedMethod === 'paypal'
                                        ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` }
                                        : { backgroundColor: colors.background, borderColor: '#374151' }
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
                                    {!getProcessor('paypal')?.available && (
                                        <p className={'mt-1 text-xs text-yellow-400'}>Unavailable</p>
                                    )}
                                </div>
                            </button>
                        )}
                    </div>
                </div>
            )}

            {/* Render the selected payment method */}
            {selectedMethod === 'stripe' && props.intent && props.stripe ? (
                <div>
                    {/* @ts-expect-error this is fine, stripe library is just weird */}
                    <Elements stripe={props.stripe} options={stripeOptions} key={props.intent.id}>
                        <PaymentButton
                            selectedNode={props.selectedNode}
                            product={props.product}
                            vars={props.vars}
                            intent={props.intent}
                            couponId={props.couponId}
                            selectedEggId={props.selectedEggId}
                            serverName={props.serverName}
                        />
                    </Elements>
                </div>
            ) : selectedMethod === 'mollie' ? (
                <div>
                    <MolliePaymentButton
                        selectedNode={props.selectedNode}
                        product={props.product}
                        vars={props.vars}
                        couponId={props.couponId}
                        selectedEggId={props.selectedEggId}
                        serverName={props.serverName}
                    />
                </div>
            ) : selectedMethod === 'paypal' ? (
                <div>
                    <PayPalPaymentButton
                        selectedNode={props.selectedNode}
                        product={props.product}
                        vars={props.vars}
                        couponId={props.couponId}
                        selectedEggId={props.selectedEggId}
                        serverName={props.serverName}
                    />
                </div>
            ) : null}
        </div>
    );
};
