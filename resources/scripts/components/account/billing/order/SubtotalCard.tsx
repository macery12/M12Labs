import { useStoreState } from '@/state/hooks';
import { type Node } from '@definitions/account/billing';
import { type BillingCycle, type EggInfo } from '@/api/routes/account/billing/products';

interface SubtotalCardProps {
    basePrice: number;
    selectedNode?: number;
    nodes?: Node[];
    selectedEggId?: number;
    availableEggs: EggInfo[];
    selectedBillingDays: number;
    billingCycles: BillingCycle[];
    couponDiscount?: number;
    couponCode?: string;
    productName?: string;
    showDetailedBreakdown?: boolean;
}

const formatPrice = (price: number) => `$${price.toFixed(2)}`;

export default ({
    basePrice,
    selectedNode,
    nodes,
    selectedEggId,
    availableEggs,
    selectedBillingDays,
    billingCycles,
    couponDiscount = 0,
    couponCode,
    productName,
    showDetailedBreakdown = false,
}: SubtotalCardProps) => {
    const { colors } = useStoreState(state => state.theme.data!);

    // Get selected cycle data
    const selectedCycle = billingCycles.find(c => c.days === selectedBillingDays);
    const selectedNodeData = nodes?.find(n => Number(n.id) === selectedNode);
    const selectedEgg = availableEggs.find(e => e.id === selectedEggId);

    // Calculate pricing
    const afterBillingCycle = basePrice * (selectedCycle?.multiplier || 1.0);
    const afterNodeMultiplier = afterBillingCycle * (selectedNodeData?.priceMultiplier || 1.0);
    const subtotal = afterNodeMultiplier;
    const total = Math.max(0, subtotal - couponDiscount);

    const hasDiscount = (selectedCycle?.discountPercent || 0) > 0;
    const hasPremium = (selectedCycle?.discountPercent || 0) < 0;
    const hasNodePremium = (selectedNodeData?.priceMultiplier || 1.0) > 1.0;
    const hasNodeDiscount = (selectedNodeData?.priceMultiplier || 1.0) < 1.0;

    const billingMultiplier = selectedCycle?.multiplier || 1.0;
    const nodeMultiplier = selectedNodeData?.priceMultiplier || 1.0;

    return (
        <div
            className={'rounded-lg border p-5 shadow-lg'}
            style={{ backgroundColor: colors.secondary, borderColor: '#374151' }}
        >
            {/* Header */}
            <div className={'mb-4'}>
                <h3 className={'text-lg font-bold text-gray-200'}>Order Summary</h3>
                {productName && <p className={'text-sm text-gray-400 mt-1'}>{productName}</p>}
            </div>

            {/* Selected Options */}
            <div className={'space-y-3 mb-4 pb-4 border-b border-gray-700'}>
                {/* Node Location */}
                {selectedNodeData ? (
                    <div className={'flex items-start justify-between text-sm'}>
                        <span className={'text-gray-400'}>Location</span>
                        <div className={'flex flex-col items-end'}>
                            <span className={'text-gray-200 font-medium'}>{selectedNodeData.name}</span>
                            {hasNodePremium && (
                                <span className={'text-xs text-red-400'}>
                                    +{(((selectedNodeData.priceMultiplier ?? 1.0) - 1) * 100).toFixed(0)}%
                                </span>
                            )}
                            {hasNodeDiscount && (
                                <span className={'text-xs text-green-400'}>
                                    {(((selectedNodeData.priceMultiplier ?? 1.0) - 1) * 100).toFixed(0)}%
                                </span>
                            )}
                        </div>
                    </div>
                ) : (
                    <div className={'flex items-center justify-between text-sm'}>
                        <span className={'text-gray-500'}>Location</span>
                        <span className={'text-gray-600'}>Not selected</span>
                    </div>
                )}

                {/* Server Type (if multiple eggs available) */}
                {availableEggs.length > 1 && (
                    selectedEgg ? (
                        <div className={'flex items-center justify-between text-sm'}>
                            <span className={'text-gray-400'}>Server Type</span>
                            <span className={'text-gray-200 font-medium'}>{selectedEgg.name}</span>
                        </div>
                    ) : (
                        <div className={'flex items-center justify-between text-sm'}>
                            <span className={'text-gray-500'}>Server Type</span>
                            <span className={'text-gray-600'}>Not selected</span>
                        </div>
                    )
                )}

                {/* Billing Cycle */}
                {selectedCycle ? (
                    <div className={'flex items-start justify-between text-sm'}>
                        <span className={'text-gray-400'}>Billing Cycle</span>
                        <div className={'flex flex-col items-end'}>
                            <span className={'text-gray-200 font-medium'}>
                                {selectedBillingDays} {selectedBillingDays === 1 ? 'day' : 'days'}
                            </span>
                            {hasDiscount && (
                                <span className={'text-xs text-green-400'}>
                                    {selectedCycle.discountPercent.toFixed(0)}% off
                                </span>
                            )}
                            {hasPremium && (
                                <span className={'text-xs text-red-400'}>
                                    +{Math.abs(selectedCycle.discountPercent).toFixed(0)}%
                                </span>
                            )}
                        </div>
                    </div>
                ) : (
                    <div className={'flex items-center justify-between text-sm'}>
                        <span className={'text-gray-500'}>Billing Cycle</span>
                        <span className={'text-gray-600'}>Not selected</span>
                    </div>
                )}
            </div>

            {/* Price Calculation */}
            {showDetailedBreakdown ? (
                <div className={'space-y-2 mb-4'}>
                    {/* Base Price */}
                    <div className={'flex items-center justify-between text-sm'}>
                        <span className={'text-gray-400'}>Base Price</span>
                        <span className={'text-gray-300'}>{formatPrice(basePrice)}</span>
                    </div>

                    {/* Billing Cycle Adjustment */}
                    {billingMultiplier !== 1.0 && (
                        <div className={'flex items-center justify-between text-sm'}>
                            <div className={'flex flex-col'}>
                                <span className={'text-gray-400'}>Billing Adjustment</span>
                                <span className={'text-xs text-gray-500'}>×{billingMultiplier.toFixed(2)}</span>
                            </div>
                            <span className={hasDiscount ? 'text-green-400' : hasPremium ? 'text-red-400' : 'text-gray-300'}>
                                {formatPrice(afterBillingCycle)}
                            </span>
                        </div>
                    )}

                    {/* Node Location Adjustment */}
                    {nodeMultiplier !== 1.0 && (
                        <div className={'flex items-center justify-between text-sm'}>
                            <div className={'flex flex-col'}>
                                <span className={'text-gray-400'}>Location Adjustment</span>
                                <span className={'text-xs text-gray-500'}>×{nodeMultiplier.toFixed(2)}</span>
                            </div>
                            <span className={hasNodeDiscount ? 'text-green-400' : hasNodePremium ? 'text-red-400' : 'text-gray-300'}>
                                {formatPrice(afterNodeMultiplier)}
                            </span>
                        </div>
                    )}

                    {/* Subtotal */}
                    {(billingMultiplier !== 1.0 || nodeMultiplier !== 1.0) && (
                        <div className={'flex items-center justify-between text-sm pt-2 border-t border-gray-700'}>
                            <span className={'text-gray-300 font-medium'}>Subtotal</span>
                            <span className={'text-gray-200 font-medium'}>{formatPrice(subtotal)}</span>
                        </div>
                    )}

                    {/* Coupon Discount */}
                    {couponDiscount > 0 && couponCode && (
                        <div className={'flex items-center justify-between text-sm'}>
                            <span className={'text-gray-400'}>Coupon ({couponCode})</span>
                            <span className={'text-green-400 font-medium'}>-{formatPrice(couponDiscount)}</span>
                        </div>
                    )}
                </div>
            ) : (
                <div className={'space-y-2 mb-4'}>
                    <div className={'flex items-center justify-between text-sm'}>
                        <span className={'text-gray-400'}>Subtotal</span>
                        <span className={'text-gray-200 font-medium'}>{formatPrice(subtotal)}</span>
                    </div>

                    {/* Coupon Discount */}
                    {couponDiscount > 0 && couponCode && (
                        <div className={'flex items-center justify-between text-sm'}>
                            <span className={'text-gray-400'}>Coupon ({couponCode})</span>
                            <span className={'text-green-400 font-medium'}>-{formatPrice(couponDiscount)}</span>
                        </div>
                    )}
                </div>
            )}

            {/* Total */}
            <div className={'pt-4 border-t border-gray-700'}>
                <div className={'flex items-center justify-between'}>
                    <span className={'text-base font-bold text-gray-200'}>Total</span>
                    <span className={'text-2xl font-bold'} style={{ color: colors.primary }}>
                        {formatPrice(total)}
                    </span>
                </div>
                
                {/* Savings indicator - only show when coupon is applied */}
                {couponDiscount > 0.01 && (
                    <div className={'mt-2 text-center text-xs text-green-400'}>
                        You're saving {formatPrice(couponDiscount)}!
                    </div>
                )}
            </div>
        </div>
    );
};
