import React from 'react';
import { Order as AccountOrder } from '@definitions/account/billing/models';
import { Order as AdminOrder } from '@definitions/admin/models';
import tw from 'twin.macro';
import { format } from 'date-fns';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faShoppingCart,
    faCreditCard,
    faCheckCircle,
    faTimesCircle,
    faClock,
    faServer,
} from '@fortawesome/free-solid-svg-icons';

interface Props {
    order: AccountOrder | AdminOrder;
}

interface TimelineEvent {
    title: string;
    description: string;
    timestamp: Date;
    icon: any;
    color: string;
}

const OrderInspectorTimelineTab: React.FC<Props> = ({ order }) => {
    // Build timeline events based on order data
    const getTimelineEvents = (): TimelineEvent[] => {
        const events: TimelineEvent[] = [];

        // Order created
        events.push({
            title: 'Order Created',
            description: `${getOrderTypeName(order.type)} order initiated for ${order.name}`,
            timestamp: order.created_at,
            icon: faShoppingCart,
            color: 'text-blue-400',
        });

        // Payment initiated
        events.push({
            title: 'Payment Initiated',
            description: `Payment process started via ${
                order.payment_processor.charAt(0).toUpperCase() + order.payment_processor.slice(1)
            }`,
            timestamp: order.created_at, // Usually same as creation
            icon: faCreditCard,
            color: 'text-purple-400',
        });

        // Payment status events
        if (order.status === 'processed') {
            events.push({
                title: 'Payment Completed',
                description: 'Payment successfully processed and verified',
                timestamp: order.updated_at || order.created_at,
                icon: faCheckCircle,
                color: 'text-green-400',
            });

            events.push({
                title: 'Order Processed',
                description: 'Order fulfillment completed',
                timestamp: order.updated_at || order.created_at,
                icon: faServer,
                color: 'text-green-400',
            });
        } else if (order.status === 'failed') {
            events.push({
                title: 'Payment Failed',
                description: 'Payment was declined or failed to process',
                timestamp: order.updated_at || order.created_at,
                icon: faTimesCircle,
                color: 'text-red-400',
            });
        } else if (order.status === 'pending') {
            events.push({
                title: 'Payment Pending',
                description: 'Awaiting payment confirmation',
                timestamp: order.created_at,
                icon: faClock,
                color: 'text-yellow-400',
            });
        }

        // PayPal specific: capture event
        if (order.payment_processor === 'paypal' && order.paypal_captured_at) {
            events.push({
                title: 'PayPal Capture Completed',
                description: `Funds captured: ${order.paypal_currency} ${order.paypal_amount?.toFixed(2)}`,
                timestamp: order.paypal_captured_at,
                icon: faCheckCircle,
                color: 'text-green-400',
            });
        }

        // Sort events by timestamp (newest first for display)
        return events.sort((a, b) => b.timestamp.getTime() - a.timestamp.getTime());
    };

    const getOrderTypeName = (type: string) => {
        switch (type) {
            case 'new':
                return 'New Purchase';
            case 'ren':
                return 'Renewal';
            case 'upg':
                return 'Upgrade';
            default:
                return type.toUpperCase();
        }
    };

    const events = getTimelineEvents();

    return (
        <div css={tw`space-y-6`}>
            <div>
                <h3 css={tw`text-lg font-semibold text-white mb-4`}>Order Timeline</h3>
                <p css={tw`text-sm text-gray-400 mb-6`}>Chronological log of events for this order</p>

                <div css={tw`relative`}>
                    {/* Timeline line */}
                    <div css={tw`absolute left-4 top-0 bottom-0 w-0.5 bg-neutral-700`} />

                    {/* Timeline events */}
                    <div css={tw`space-y-6`}>
                        {events.map((event, index) => (
                            <div key={index} css={tw`relative flex gap-4`}>
                                {/* Icon */}
                                <div
                                    className={`flex-shrink-0 w-8 h-8 rounded-full bg-neutral-800 border-2 border-neutral-700 flex items-center justify-center z-10 ${event.color}`}
                                >
                                    <FontAwesomeIcon icon={event.icon} size="sm" />
                                </div>

                                {/* Content */}
                                <div css={tw`flex-1 bg-neutral-900 rounded-lg p-4 border border-neutral-700`}>
                                    <div css={tw`flex justify-between items-start mb-2`}>
                                        <h4 css={tw`text-sm font-semibold text-white`}>{event.title}</h4>
                                        <time css={tw`text-xs text-gray-500`}>
                                            {format(event.timestamp, 'MMM dd, HH:mm:ss')}
                                        </time>
                                    </div>
                                    <p css={tw`text-sm text-gray-400`}>{event.description}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Additional info */}
            <div css={tw`bg-neutral-900 rounded-lg p-4 border border-neutral-700`}>
                <p css={tw`text-xs text-gray-500`}>
                    <strong>Note:</strong> This timeline shows key events in the order lifecycle. Webhook events and
                    detailed provider responses may not be fully represented in this view.
                </p>
            </div>
        </div>
    );
};

export default OrderInspectorTimelineTab;
