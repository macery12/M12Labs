import { differenceInHours, format, formatDistanceToNow } from 'date-fns';
import { InstalledAddon } from '@/api/routes/server/plugins';
import Spinner from '@/elements/Spinner';
import tw from 'twin.macro';
import { bytesToString } from '@/lib/formatters';

interface Props {
    mods: InstalledAddon[];
    plugins: InstalledAddon[];
    loading: boolean;
}

const Section = ({ title, items }: { title: string; items: InstalledAddon[] }) => {
    return (
        <div css={tw`space-y-2`}>
            <div css={tw`flex items-center justify-between`}>
                <h3 css={tw`text-lg font-semibold text-neutral-100`}>{title}</h3>
                <span css={tw`text-xs text-neutral-400`}>{items.length} installed</span>
            </div>

            {items.length === 0 ? (
                <div css={tw`text-sm text-neutral-400 border border-dashed border-neutral-700 rounded p-4`}>
                    Nothing found in this directory.
                </div>
            ) : (
                <div css={tw`divide-y divide-neutral-700 border border-neutral-700 rounded`}>
                    {items.map(item => (
                        <div key={item.path} css={tw`flex flex-col sm:flex-row sm:items-center px-4 py-3 gap-2`}>
                            <div css={tw`flex-1 min-w-0`}>
                                <div css={tw`flex items-center gap-2`}>
                                    <span css={tw`font-medium text-neutral-100 truncate`}>{item.displayName}</span>
                                    {item.disabled && (
                                        <span
                                            css={tw`text-[11px] uppercase tracking-wide bg-yellow-500 bg-opacity-20 text-yellow-200 px-2 py-0.5 rounded`}
                                        >
                                            Disabled
                                        </span>
                                    )}
                                </div>
                                <div css={tw`text-xs text-neutral-400 truncate`}>{item.path}</div>
                            </div>
                            <div css={tw`flex items-center gap-6 text-sm text-neutral-300`}>
                                <span css={tw`whitespace-nowrap`}>{bytesToString(item.size)}</span>
                                <span css={tw`text-right text-neutral-400 whitespace-nowrap`} title={item.modifiedAt?.toString()}>
                                    {item.modifiedAt
                                        ? Math.abs(differenceInHours(item.modifiedAt, new Date())) > 48
                                            ? format(item.modifiedAt, 'MMM do, yyyy h:mma')
                                            : formatDistanceToNow(item.modifiedAt, { addSuffix: true })
                                        : 'Unknown'}
                                </span>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
};

const InstalledAddonsList = ({ mods, plugins, loading }: Props) => {
    if (loading) {
        return <Spinner size={'large'} centered />;
    }

    return (
        <div css={tw`space-y-6`}>
            <Section title={'Mods'} items={mods} />
            <Section title={'Plugins'} items={plugins} />
        </div>
    );
};

export default InstalledAddonsList;
