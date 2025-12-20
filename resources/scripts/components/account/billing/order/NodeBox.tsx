import { useStoreState } from '@/state/hooks';
import { Dispatch, SetStateAction } from 'react';
import GreyRowBox from '@/elements/GreyRowBox';
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

    return (
        <div onClick={() => setSelected(Number(node.id))} className={'relative'}>
            <GreyRowBox>
                <CheckCircleIcon
                    className={classNames(
                        'absolute top-2 right-2 h-5 w-5 transition-colors duration-500',
                        selected === Number(node.id) ? 'text-green-500' : 'text-gray-500',
                    )}
                />
                <ServerIcon className={'mr-2 h-8 w-8'} style={{ color: colors.primary }} />
                <p className={'font-semibold text-gray-200'}>{node.name}</p>
            </GreyRowBox>
        </div>
    );
};
