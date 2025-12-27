import tw from 'twin.macro';
import { useEffect } from 'react';
import useFlash from '@/plugins/useFlash';
import AlertRenderer from '@/components/AlertRenderer';
import SuspendUserBox from './SuspendUserBox';
import DeleteUserBox from './DeleteUserBox';

export default () => {
    const { clearFlashes } = useFlash();

    useEffect(() => {
        clearFlashes('user:manage');
    }, []);

    return (
        <div css={tw`grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-2 gap-y-2`}>
            <AlertRenderer
                filterByKey={'user:manage'}
                className={'mb-4 md:col-span-2 xl:col-span-3'}
                position="top-center"
            />
            <SuspendUserBox />
            <DeleteUserBox />
        </div>
    );
};
