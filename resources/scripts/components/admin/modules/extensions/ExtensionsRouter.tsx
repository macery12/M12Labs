import { useEffect } from 'react';
import { useStoreState } from '@/state/hooks';
import { Route, Routes } from 'react-router-dom';
import { NotFound } from '@/elements/ScreenBlock';
import EnableExtensionsContainer from './EnableExtensionsContainer';
import ExtensionsContainer from './ExtensionsContainer';
import { PuzzleIcon, CogIcon } from '@heroicons/react/outline';
import { SubNavigation, SubNavigationLink } from '@admin/SubNavigation';
import FlashMessageRender from '@/elements/FlashMessageRender';
import ToggleExtensionsButton from './ToggleExtensionsButton';

export default () => {
    const enabled = useStoreState(state => state.everest.data?.extensions?.enabled);

    useEffect(() => {
        document.title = 'Admin | Extensions';
    }, []);

    if (!enabled) return <EnableExtensionsContainer />;

    return (
        <>
            <FlashMessageRender byKey={'admin:extensions'} className={'mb-4'} />
            <div className={'mb-8 flex w-full flex-row items-center'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Extensions Module</h2>
                    <p
                        className={
                            'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                        }
                    >
                        Configure and manage server extensions.
                    </p>
                </div>
                <div className={'ml-auto'}>
                    <ToggleExtensionsButton />
                </div>
            </div>
            <SubNavigation>
                <SubNavigationLink to={'/admin/extensions'} name={'Extensions'} base>
                    <PuzzleIcon />
                </SubNavigationLink>
            </SubNavigation>
            <Routes>
                <Route path={'/'} element={<ExtensionsContainer />} />
                <Route path={'/*'} element={<NotFound />} />
            </Routes>
            <p className={'mb-8 mt-4 text-center text-xs text-neutral-500'}>
                &copy; {new Date().getFullYear()}&nbsp;
                <a
                    rel={'noopener nofollow noreferrer'}
                    href={'https://jexpanel.com'}
                    target={'_blank'}
                    className={'text-neutral-500 no-underline hover:text-neutral-300'}
                >
                    Jexpanel.com
                </a>
            </p>
        </>
    );
};
