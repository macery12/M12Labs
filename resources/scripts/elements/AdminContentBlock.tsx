import type { ReactNode } from 'react';
import { useEffect } from 'react';
import tw from 'twin.macro';
import AlertRenderer from '@/components/AlertRenderer';

const AdminContentBlock: React.FC<{
    children: ReactNode;
    title?: string;
    showFlashKey?: string;
}> = ({ children, title, showFlashKey }) => {
    useEffect(() => {
        if (!title) return;
        document.title = `Admin | ${title}`;
    }, [title]);

    return (
        <>
            {showFlashKey && <AlertRenderer filterByKey={showFlashKey} className="mb-4" position="top-center" />}
            {children}
            <p css={tw`text-center text-neutral-500 text-xs mt-4 mb-8`}>
                &copy; {new Date().getFullYear()}&nbsp;
                <a
                    rel={'noopener nofollow noreferrer'}
                    href={'https://jexpanel.com'}
                    target={'_blank'}
                    css={tw`no-underline text-neutral-500 hover:text-neutral-300`}
                >
                    Jexpanel.com
                </a>
            </p>
        </>
    );
};

export default AdminContentBlock;
