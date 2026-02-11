import { useStoreState } from '@/state/hooks';
import { Dispatch, SetStateAction } from 'react';
import { CheckCircleIcon, CalendarIcon } from '@heroicons/react/solid';
import classNames from 'classnames';
import { BillingCycle } from '@/api/routes/account/billing/products';

interface Props {
    cycle: BillingCycle;
    selected: number;
    setSelected: Dispatch<SetStateAction<number>>;
}

export default ({ cycle, selected, setSelected }: Props) => {
    const { colors } = useStoreState(s => s.theme.data!);
    const isSelected = selected === cycle.days;

    const getDiscountLabel = () => {
        if (cycle.discountPercent === 0) {
            return null;
        }
        if (cycle.discountPercent > 0) {
            return (
                <span className={'text-xs font-medium'} style={{ color: colors.primary }}>
                    {cycle.discountPercent.toFixed(0)}% discount
                </span>
            );
        }
        return (
            <span className={'text-xs font-medium text-amber-400'}>
                {Math.abs(cycle.discountPercent).toFixed(0)}% premium
            </span>
        );
    };

    return (
        <div
            onClick={() => setSelected(cycle.days)}
            className={classNames(
                'relative cursor-pointer rounded-lg border-2 p-4 transition-all hover:scale-[1.02]',
                isSelected ? 'border-gray-600 hover:border-gray-500' : 'border-gray-700 hover:border-gray-600',
            )}
            style={isSelected ? { borderColor: colors.primary, backgroundColor: `${colors.primary}15` } : {}}
        >
            <div className={'flex items-center justify-between gap-3'}>
                <div className={'flex items-center gap-3'}>
                    <CalendarIcon className={'h-6 w-6 flex-shrink-0'} style={{ color: colors.primary }} />
                    <div>
                        <div className={'flex items-center gap-2'}>
                            <p className={'font-semibold text-gray-200'}>
                                {cycle.days} {cycle.days === 1 ? 'Day' : 'Days'}
                            </p>
                            {cycle.isDefault && (
                                <span
                                    className={'rounded px-1.5 py-0.5 text-xs font-medium'}
                                    style={{ backgroundColor: `${colors.primary}25`, color: colors.primary }}
                                >
                                    Default
                                </span>
                            )}
                        </div>
                        {getDiscountLabel()}
                    </div>
                </div>
                <div className={'flex items-center gap-3'}>
                    <span className={'text-xl font-bold whitespace-nowrap'} style={{ color: colors.primary }}>
                        ${cycle.price.toFixed(2)}
                    </span>
                    <CheckCircleIcon
                        className={classNames(
                            'h-5 w-5 flex-shrink-0 transition-colors',
                            isSelected ? '' : 'text-gray-600',
                        )}
                        style={isSelected ? { color: colors.primary } : {}}
                    />
                </div>
            </div>
        </div>
    );
};
