import React from 'react';
import { Order as AccountOrder } from '@definitions/account/billing/models';
import { Order as AdminOrder } from '@definitions/admin/models';
import tw from 'twin.macro';
import { formatDistanceToNowStrict, format } from 'date-fns';
import CopyOnClick from '@/elements/CopyOnClick';
import Pill from '@/elements/Pill';

interface Props {
    order: AccountOrder | AdminOrder;
    isAdmin?: boolean;
}

const OrderInspectorOverviewTab: React.FC<Props> = ({ order, isAdmin = false }) => {
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

    const getOrderTypeColor = (type: string) => {
        switch (type) {
            case 'new':
                return 'success';
            case 'ren':
                return 'info';
            case 'upg':
                return 'warn';
            default:
                return 'unknown';
        }
    };

    return (
        <div css={tw`space-y-6`}>
            {/* Order Information */}
            <div>
                <h3 css={tw`text-lg font-semibold text-white mb-4`}>Order Information</h3>
                <div css={tw`bg-neutral-900 rounded-lg p-4 space-y-3`}>
                    <div css={tw`flex justify-between items-start`}>
                        <span css={tw`text-sm text-gray-400`}>Order ID</span>
                        <CopyOnClick text={order.id.toString()}>
                            <code css={tw`text-sm font-mono text-white bg-neutral-800 px-2 py-1 rounded cursor-pointer hover:bg-neutral-700 transition-colors`}>
                                {order.id}
                            </code>
                        </CopyOnClick>
                    </div>
                    <div css={tw`flex justify-between items-start`}>
                        <span css={tw`text-sm text-gray-400`}>Order Type</span>
                        <Pill size="small" type={getOrderTypeColor(order.type)}>
                            {getOrderTypeName(order.type)}
                        </Pill>
                    </div>
                    <div css={tw`flex justify-between items-start`}>
                        <span css={tw`text-sm text-gray-400`}>Created</span>
                        <div css={tw`text-right`}>
                            <div css={tw`text-sm text-white`}>
                                {format(order.created_at, 'MMM dd, yyyy HH:mm:ss')}
                            </div>
                            <div css={tw`text-xs text-gray-500`}>
                                {formatDistanceToNowStrict(order.created_at, { addSuffix: true })}
                            </div>
                        </div>
                    </div>
                    {order.updated_at && (
                        <div css={tw`flex justify-between items-start`}>
                            <span css={tw`text-sm text-gray-400`}>Last Updated</span>
                            <div css={tw`text-right`}>
                                <div css={tw`text-sm text-white`}>
                                    {format(order.updated_at, 'MMM dd, yyyy HH:mm:ss')}
                                </div>
                                <div css={tw`text-xs text-gray-500`}>
                                    {formatDistanceToNowStrict(order.updated_at, { addSuffix: true })}
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {/* Product Information */}
            <div>
                <h3 css={tw`text-lg font-semibold text-white mb-4`}>Product & Billing</h3>
                <div css={tw`bg-neutral-900 rounded-lg p-4 space-y-3`}>
                    <div css={tw`flex justify-between items-start`}>
                        <span css={tw`text-sm text-gray-400`}>Product Name</span>
                        <span css={tw`text-sm text-white font-medium`}>{order.name}</span>
                    </div>
                    <div css={tw`flex justify-between items-start`}>
                        <span css={tw`text-sm text-gray-400`}>Description</span>
                        <span css={tw`text-sm text-white max-w-md text-right`}>{order.description}</span>
                    </div>
                    <div css={tw`flex justify-between items-start`}>
                        <span css={tw`text-sm text-gray-400`}>Product ID</span>
                        <code css={tw`text-sm font-mono text-gray-300`}>{order.product_id}</code>
                    </div>
                    {'egg_id' in order && order.egg_id && (
                        <div css={tw`flex justify-between items-start`}>
                            <span css={tw`text-sm text-gray-400`}>Egg ID</span>
                            <code css={tw`text-sm font-mono text-gray-300`}>{order.egg_id}</code>
                        </div>
                    )}
                    <div css={tw`flex justify-between items-start border-t border-neutral-800 pt-3 mt-3`}>
                        <span css={tw`text-sm text-gray-400 font-medium`}>Total Amount</span>
                        <span css={tw`text-lg text-white font-bold`}>
                            ${order.total.toFixed(2)}
                            {order.type === 'ren' && <span css={tw`text-sm text-gray-400 ml-1`}>/mo</span>}
                        </span>
                    </div>
                </div>
            </div>

            {/* Customer Information (Admin only) */}
            {isAdmin && 'user_id' in order && (
                <div>
                    <h3 css={tw`text-lg font-semibold text-white mb-4`}>Customer Information</h3>
                    <div css={tw`bg-neutral-900 rounded-lg p-4 space-y-3`}>
                        <div css={tw`flex justify-between items-start`}>
                            <span css={tw`text-sm text-gray-400`}>User ID</span>
                            <code css={tw`text-sm font-mono text-gray-300`}>{order.user_id}</code>
                        </div>
                    </div>
                </div>
            )}

            {/* Additional Details */}
            <div>
                <h3 css={tw`text-lg font-semibold text-white mb-4`}>Additional Details</h3>
                <div css={tw`bg-neutral-900 rounded-lg p-4 space-y-3`}>
                    <div css={tw`flex justify-between items-start`}>
                        <span css={tw`text-sm text-gray-400`}>Status</span>
                        <span css={tw`text-sm text-white capitalize`}>{order.status}</span>
                    </div>
                    <div css={tw`flex justify-between items-start`}>
                        <span css={tw`text-sm text-gray-400`}>Payment Provider</span>
                        <span css={tw`text-sm text-white capitalize`}>{order.payment_processor}</span>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default OrderInspectorOverviewTab;
