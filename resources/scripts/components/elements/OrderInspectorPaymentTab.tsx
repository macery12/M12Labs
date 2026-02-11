import React from 'react';
import { Order as AccountOrder } from '@definitions/account/billing/models';
import { Order as AdminOrder } from '@definitions/admin/models';
import tw from 'twin.macro';
import CopyOnClick from '@/elements/CopyOnClick';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExternalLinkAlt } from '@fortawesome/free-solid-svg-icons';
import { formatDistanceToNowStrict, format } from 'date-fns';
import PaymentProcessorBadge from '@/components/elements/PaymentProcessorBadge';

interface Props {
    order: AccountOrder | AdminOrder;
}

const OrderInspectorPaymentTab: React.FC<Props> = ({ order }) => {
    const getProviderDashboardUrl = () => {
        switch (order.payment_processor) {
            case 'stripe':
                if (order.payment_intent_id) {
                    return `https://dashboard.stripe.com/payments/${order.payment_intent_id}`;
                }
                return null;
            case 'mollie':
                if (order.mollie_payment_id) {
                    return `https://www.mollie.com/dashboard/payments/${order.mollie_payment_id}`;
                }
                return null;
            case 'paypal':
                if (order.paypal_order_id) {
                    return `https://www.paypal.com/activity/payment/${order.paypal_order_id}`;
                }
                return null;
            default:
                return null;
        }
    };

    const providerUrl = getProviderDashboardUrl();

    const renderStripeDetails = () => (
        <div css={tw`space-y-3`}>
            {order.payment_intent_id && (
                <div css={tw`flex justify-between items-start`}>
                    <span css={tw`text-sm text-gray-400`}>Payment Intent ID</span>
                    <CopyOnClick text={order.payment_intent_id}>
                        <code
                            css={tw`text-sm font-mono text-white bg-neutral-800 px-2 py-1 rounded cursor-pointer hover:bg-neutral-700 transition-colors`}
                        >
                            {order.payment_intent_id.length > 30
                                ? `${order.payment_intent_id.substring(0, 30)}...`
                                : order.payment_intent_id}
                        </code>
                    </CopyOnClick>
                </div>
            )}
        </div>
    );

    const renderMollieDetails = () => (
        <div css={tw`space-y-3`}>
            {order.mollie_payment_id && (
                <div css={tw`flex justify-between items-start`}>
                    <span css={tw`text-sm text-gray-400`}>Mollie Payment ID</span>
                    <CopyOnClick text={order.mollie_payment_id}>
                        <code
                            css={tw`text-sm font-mono text-white bg-neutral-800 px-2 py-1 rounded cursor-pointer hover:bg-neutral-700 transition-colors`}
                        >
                            {order.mollie_payment_id}
                        </code>
                    </CopyOnClick>
                </div>
            )}
            {order.payment_intent_id && (
                <div css={tw`flex justify-between items-start`}>
                    <span css={tw`text-sm text-gray-400`}>Payment Intent ID</span>
                    <CopyOnClick text={order.payment_intent_id}>
                        <code
                            css={tw`text-sm font-mono text-white bg-neutral-800 px-2 py-1 rounded cursor-pointer hover:bg-neutral-700 transition-colors`}
                        >
                            {order.payment_intent_id.length > 30
                                ? `${order.payment_intent_id.substring(0, 30)}...`
                                : order.payment_intent_id}
                        </code>
                    </CopyOnClick>
                </div>
            )}
        </div>
    );

    const renderPayPalDetails = () => (
        <div css={tw`space-y-3`}>
            {order.paypal_order_id && (
                <div css={tw`flex justify-between items-start`}>
                    <span css={tw`text-sm text-gray-400`}>PayPal Order ID</span>
                    <CopyOnClick text={order.paypal_order_id}>
                        <code
                            css={tw`text-sm font-mono text-white bg-neutral-800 px-2 py-1 rounded cursor-pointer hover:bg-neutral-700 transition-colors`}
                        >
                            {order.paypal_order_id}
                        </code>
                    </CopyOnClick>
                </div>
            )}
            {order.paypal_capture_id && (
                <div css={tw`flex justify-between items-start`}>
                    <span css={tw`text-sm text-gray-400`}>Capture ID</span>
                    <CopyOnClick text={order.paypal_capture_id}>
                        <code
                            css={tw`text-sm font-mono text-white bg-neutral-800 px-2 py-1 rounded cursor-pointer hover:bg-neutral-700 transition-colors`}
                        >
                            {order.paypal_capture_id}
                        </code>
                    </CopyOnClick>
                </div>
            )}
            {order.paypal_payer_id && (
                <div css={tw`flex justify-between items-start`}>
                    <span css={tw`text-sm text-gray-400`}>Payer ID</span>
                    <CopyOnClick text={order.paypal_payer_id}>
                        <code
                            css={tw`text-sm font-mono text-white bg-neutral-800 px-2 py-1 rounded cursor-pointer hover:bg-neutral-700 transition-colors`}
                        >
                            {order.paypal_payer_id}
                        </code>
                    </CopyOnClick>
                </div>
            )}
            {order.paypal_payer_email && (
                <div css={tw`flex justify-between items-start`}>
                    <span css={tw`text-sm text-gray-400`}>Payer Email</span>
                    <span css={tw`text-sm text-white`}>{order.paypal_payer_email}</span>
                </div>
            )}
            {order.paypal_status && (
                <div css={tw`flex justify-between items-start`}>
                    <span css={tw`text-sm text-gray-400`}>PayPal Status</span>
                    <span css={tw`text-sm text-white capitalize`}>{order.paypal_status}</span>
                </div>
            )}
            {order.paypal_amount && order.paypal_currency && (
                <div css={tw`flex justify-between items-start`}>
                    <span css={tw`text-sm text-gray-400`}>PayPal Amount</span>
                    <span css={tw`text-sm text-white font-bold`}>
                        {order.paypal_currency} {order.paypal_amount.toFixed(2)}
                    </span>
                </div>
            )}
            {order.paypal_captured_at && (
                <div css={tw`flex justify-between items-start`}>
                    <span css={tw`text-sm text-gray-400`}>Captured</span>
                    <div css={tw`text-right`}>
                        <div css={tw`text-sm text-white`}>
                            {format(order.paypal_captured_at, 'MMM dd, yyyy HH:mm:ss')}
                        </div>
                        <div css={tw`text-xs text-gray-500`}>
                            {formatDistanceToNowStrict(order.paypal_captured_at, { addSuffix: true })}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );

    return (
        <div css={tw`space-y-6`}>
            {/* Provider Information */}
            <div>
                <h3 css={tw`text-lg font-semibold text-white mb-4`}>Payment Provider</h3>
                <div css={tw`bg-neutral-900 rounded-lg p-4 space-y-3`}>
                    <div css={tw`flex justify-between items-center`}>
                        <span css={tw`text-sm text-gray-400`}>Provider</span>
                        <PaymentProcessorBadge processor={order.payment_processor} size="medium" />
                    </div>
                    {providerUrl && (
                        <div css={tw`pt-3 border-t border-neutral-800`}>
                            <a
                                href={providerUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                css={tw`inline-flex items-center gap-2 text-sm text-blue-400 hover:text-blue-300 transition-colors`}
                            >
                                <FontAwesomeIcon icon={faExternalLinkAlt} />
                                View in{' '}
                                {order.payment_processor.charAt(0).toUpperCase() +
                                    order.payment_processor.slice(1)}{' '}
                                Dashboard
                            </a>
                        </div>
                    )}
                </div>
            </div>

            {/* Transaction Details */}
            <div>
                <h3 css={tw`text-lg font-semibold text-white mb-4`}>Transaction Details</h3>
                <div css={tw`bg-neutral-900 rounded-lg p-4`}>
                    {order.payment_processor === 'stripe' && renderStripeDetails()}
                    {order.payment_processor === 'mollie' && renderMollieDetails()}
                    {order.payment_processor === 'paypal' && renderPayPalDetails()}
                </div>
            </div>

            {/* Payment Status Mapping */}
            <div>
                <h3 css={tw`text-lg font-semibold text-white mb-4`}>Status Information</h3>
                <div css={tw`bg-neutral-900 rounded-lg p-4 space-y-3`}>
                    <div css={tw`flex justify-between items-start`}>
                        <span css={tw`text-sm text-gray-400`}>Order Status</span>
                        <span css={tw`text-sm text-white capitalize font-medium`}>{order.status}</span>
                    </div>
                    <div css={tw`flex justify-between items-start`}>
                        <span css={tw`text-sm text-gray-400`}>Total Amount</span>
                        <span css={tw`text-sm text-white font-bold`}>${order.total.toFixed(2)}</span>
                    </div>
                    <div css={tw`pt-3 border-t border-neutral-800 text-xs text-gray-500`}>
                        <p>
                            <strong>Status Mapping:</strong>{' '}
                            {order.status === 'processed'
                                ? 'Payment successfully processed and order completed'
                                : order.status === 'pending'
                                ? 'Payment is pending confirmation'
                                : order.status === 'failed'
                                ? 'Payment failed or was declined'
                                : 'Order status unknown or expired'}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default OrderInspectorPaymentTab;
