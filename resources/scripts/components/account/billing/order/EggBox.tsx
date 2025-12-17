import { useStoreState } from '@/state/hooks';
import { Dispatch, SetStateAction } from 'react';
import { CheckCircleIcon, CubeIcon } from '@heroicons/react/solid';
import classNames from 'classnames';
import { type EggInfo } from '@/api/routes/account/billing/products';

interface Props {
    egg: EggInfo;
    selected: number | undefined;
    setSelected: Dispatch<SetStateAction<number | undefined>>;
    onEggChange?: () => void;
}

export default ({ egg, selected, setSelected, onEggChange }: Props) => {
    const { colors } = useStoreState(s => s.theme.data!);
    const isSelected = selected === egg.id;

    const handleClick = () => {
        if (selected !== egg.id && onEggChange) {
            onEggChange();
        }
        setSelected(egg.id);
    };

    return (
        <div
            onClick={handleClick}
            className={classNames(
                'relative rounded-lg border-2 p-4 transition-all cursor-pointer',
                isSelected ? 'border-green-500 bg-green-500/10' : 'border-gray-700 bg-gray-800 hover:border-gray-600',
            )}
        >
            <div className={'flex items-start gap-3'}>
                <CubeIcon
                    className={'h-8 w-8 flex-shrink-0'}
                    style={{ color: isSelected ? '#10b981' : colors.primary }}
                />
                <div className={'flex-1 min-w-0'}>
                    <p className={'font-semibold text-gray-200'}>{egg.name}</p>
                    {egg.description && (
                        <p
                            className={'mt-1 text-xs text-gray-400 overflow-hidden'}
                            style={{
                                display: '-webkit-box',
                                WebkitLineClamp: 2,
                                WebkitBoxOrient: 'vertical',
                            }}
                        >
                            {egg.description}
                        </p>
                    )}
                </div>
                <CheckCircleIcon
                    className={classNames(
                        'h-6 w-6 transition-colors flex-shrink-0',
                        isSelected ? 'text-green-500' : 'text-gray-600',
                    )}
                />
            </div>
        </div>
    );
};
