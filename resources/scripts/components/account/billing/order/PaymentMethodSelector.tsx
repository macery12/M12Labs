import { useState } from 'react';
import { useStoreState } from '@/state/hooks';
import { Product, StripeIntent } from '@definitions/account/billing';
import PaymentButton from './PaymentButton';
import MolliePaymentButton from './MolliePaymentButton';
import { Elements } from '@stripe/react-stripe-js';
import { Stripe } from '@stripe/stripe-js';
import classNames from 'classnames';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCreditCard, faCheck } from '@fortawesome/free-solid-svg-icons';

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

type PaymentMethod = 'stripe' | 'mollie';

export default (props: Props) => {
    const { colors } = useStoreState(state => state.theme.data!);
    const billing = useStoreState(state => state.everest.data!.billing);
    
    // Determine available payment methods
    const availableProcessors: PaymentMethod[] = [];
    
    if (billing.processors?.stripe?.available) {
        availableProcessors.push('stripe');
    }
    
    if (billing.processors?.mollie?.available) {
        availableProcessors.push('mollie');
    }

    // Default to first available processor
    const [selectedMethod, setSelectedMethod] = useState<PaymentMethod>(
        availableProcessors[0] || 'stripe'
    );

    // If only one processor is available, don't show selection UI
    const showSelection = availableProcessors.length > 1;

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
                        {availableProcessors.includes('stripe') && (
                            <button
                                type={'button'}
                                onClick={() => setSelectedMethod('stripe')}
                                className={classNames(
                                    'relative flex items-center gap-3 rounded-lg border-2 p-4 text-left transition-all',
                                    'hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-offset-2',
                                    selectedMethod === 'stripe'
                                        ? 'border-primary bg-opacity-20'
                                        : 'border-gray-600 bg-transparent'
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
                                        selectedMethod === 'stripe' ? 'text-white' : 'text-gray-400'
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
                                        : 'border-gray-600 bg-transparent'
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
                                        selectedMethod === 'mollie' ? 'text-white' : 'text-gray-400'
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
                                    <p className={'mt-0.5 text-xs text-gray-400'}>
                                        Card, iDEAL, and more
                                    </p>
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
            ) : null}
        </div>
    );
};
