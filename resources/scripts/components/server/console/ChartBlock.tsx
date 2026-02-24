import * as React from 'react';
import classNames from 'classnames';
import styles from '@server/console/style.module.css';
import { useStoreState } from '@/state/hooks';

interface ChartBlockProps {
    title: string;
    legend?: React.ReactNode;
    children: React.ReactNode;
}

export default ({ title, legend, children }: ChartBlockProps) => {
    const { secondary, headers, borders } = useStoreState(state => state.theme.data!.colors);

    return (
        <div
            className={classNames(styles.chart_container, 'group flex h-full flex-col rounded-md border')}
            style={{ backgroundColor: secondary, borderColor: borders }}
        >
            <div
                className={'flex items-center justify-between px-4 py-2'}
                style={{ borderBottom: `1px solid ${borders}`, backgroundColor: headers }}
            >
                <h3 className={'font-header text-sm transition-colors duration-100 group-hover:text-slate-50'}>{title}</h3>
                {legend && <p className={'flex items-center text-xs text-slate-200'}>{legend}</p>}
            </div>
            <div className={'flex-1 overflow-hidden p-3'}>
                <div className={'h-full w-full'}>{children}</div>
            </div>
        </div>
    );
};
