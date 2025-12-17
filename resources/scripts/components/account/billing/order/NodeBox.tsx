import { useStoreState } from '@/state/hooks';
import { Dispatch, SetStateAction } from 'react';
import { CheckCircleIcon, ServerIcon } from '@heroicons/react/solid';
import classNames from 'classnames';
import { type Node } from '@definitions/account/billing';

interface Props {
    node: Node;
    selected: number | undefined;
    setSelected: Dispatch<SetStateAction<number>>;
}

export default ({ node, selected, setSelected }: Props) => {
    const { colors } = useStoreState(s => s.theme.data!);
    const isSelected = selected === Number(node.id);

    return (
        <div
            onClick={() => setSelected(Number(node.id))}
            className={classNames(
                'relative rounded-lg border-2 p-4 transition-all cursor-pointer',
                isSelected ? 'border-green-500 bg-green-500/10' : 'border-gray-700 bg-gray-800 hover:border-gray-600',
            )}
        >
            <div className={'flex items-center gap-3'}>
                <ServerIcon className={'h-8 w-8'} style={{ color: isSelected ? '#10b981' : colors.primary }} />
                <div className={'flex-1'}>
                    <p className={'font-semibold text-gray-200'}>{node.name}</p>
                </div>
                <CheckCircleIcon
                    className={classNames('h-6 w-6 transition-colors', isSelected ? 'text-green-500' : 'text-gray-600')}
                />
            </div>
        </div>
    );
};
