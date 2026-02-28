import { differenceInHours, format, formatDistanceToNow } from 'date-fns';
import { InstalledAddon, InstalledAddonResponse } from '@/api/routes/server/plugins';
import Spinner from '@/elements/Spinner';
import tw from 'twin.macro';
import { bytesToString } from '@/lib/formatters';
import Button from '@/elements/button/Button';
import ConfirmationModal from '@/elements/ConfirmationModal';
import Modal from '@/elements/Modal';
import { useEffect, useMemo, useState } from 'react';

interface Props {
    mods: InstalledAddon[];
    plugins: InstalledAddon[];
    loading: boolean;
    stats?: InstalledAddonResponse['stats'];
    scanInProgress?: boolean;
    onRescan: () => Promise<void> | void;
    onDelete: (paths: string[]) => Promise<void> | void;
    onToggle: (paths: string[], disabled: boolean) => Promise<void> | void;
}

type TabKey = 'installed' | 'disabled';

const formatWhen = (date: Date | null) => {
    if (!date) return 'Unknown';

    const hourDelta = differenceInHours(new Date(), date);
    const farApart = Math.abs(hourDelta) > 48;

    return farApart ? format(date, 'MMM do, yyyy h:mm a') : formatDistanceToNow(date, { addSuffix: true });
};

const AddonCard = ({
    addon,
    selected,
    onSelect,
    onDetails,
}: {
    addon: InstalledAddon;
    selected: boolean;
    onSelect: (path: string) => void;
    onDetails: (addon: InstalledAddon) => void;
}) => {
    return (
        <div css={tw`border border-neutral-700 rounded-lg p-4 flex gap-4 items-start bg-neutral-900`}>
            <input
                type="checkbox"
                aria-label={`Select ${addon.displayName}`}
                checked={selected}
                onChange={() => onSelect(addon.path)}
                css={tw`mt-1`}
            />
            <div css={tw`w-12 h-12 rounded bg-neutral-800 overflow-hidden flex items-center justify-center`}>
                {addon.iconUrl ? (
                    <img src={addon.iconUrl} alt={addon.displayName} css={tw`w-full h-full object-cover`} />
                ) : (
                    <div css={tw`text-lg font-semibold text-neutral-300`}>{addon.displayName?.[0] ?? '?'}</div>
                )}
            </div>
            <div css={tw`flex-1 min-w-0 space-y-1`}>
                <div css={tw`flex items-center gap-2`}>
                    <p css={tw`text-base font-semibold text-neutral-100 truncate`}>{addon.displayName}</p>
                    {addon.disabled && (
                        <span
                            css={tw`text-[11px] uppercase tracking-wide bg-yellow-500 bg-opacity-20 text-yellow-200 px-2 py-0.5 rounded`}
                        >
                            Disabled
                        </span>
                    )}
                    {addon.parsing && (
                        <span
                            css={tw`text-[11px] uppercase tracking-wide bg-blue-500 bg-opacity-20 text-blue-200 px-2 py-0.5 rounded`}
                        >
                            Parsing
                        </span>
                    )}
                    {addon.parseError && (
                        <span
                            css={tw`text-[11px] uppercase tracking-wide bg-red-500 bg-opacity-20 text-red-200 px-2 py-0.5 rounded`}
                            title={addon.parseError}
                        >
                            Parse Error
                        </span>
                    )}
                </div>
                <p css={tw`text-xs text-neutral-400 truncate`}>{addon.path}</p>
                <p css={tw`text-sm text-neutral-300 line-clamp-2`}>
                    {addon.description || (addon.parsing ? 'Parsing metadata…' : 'No description provided.')}
                </p>
                <div css={tw`flex flex-wrap gap-3 text-xs text-neutral-400`}>
                    {addon.loader && <span css={tw`px-2 py-0.5 rounded bg-neutral-800 text-neutral-200`}>{addon.loader}</span>}
                    {addon.version && <span css={tw`px-2 py-0.5 rounded bg-neutral-800 text-neutral-200`}>v{addon.version}</span>}
                    <span>{bytesToString(addon.size)}</span>
                    <span title={addon.modifiedAt ? format(addon.modifiedAt, 'PPpp') : undefined}>{formatWhen(addon.modifiedAt)}</span>
                    <button css={tw`text-blue-200 hover:text-blue-100 text-xs`} onClick={() => onDetails(addon)}>
                        Details
                    </button>
                </div>
            </div>
        </div>
    );
};

const Section = ({
    title,
    items,
    selected,
    onSelect,
    onDetails,
}: {
    title: string;
    items: InstalledAddon[];
    selected: Set<string>;
    onSelect: (path: string) => void;
    onDetails: (addon: InstalledAddon) => void;
}) => {
    return (
        <div css={tw`space-y-3`}>
            <div css={tw`flex items-center justify-between`}>
                <h3 css={tw`text-lg font-semibold text-neutral-100`}>{title}</h3>
                <span css={tw`text-xs text-neutral-400`}>{items.length} items</span>
            </div>

            {items.length === 0 ? (
                <div css={tw`text-sm text-neutral-400 border border-dashed border-neutral-700 rounded p-4`}>
                    Nothing found in this directory.
                </div>
            ) : (
                <div css={tw`space-y-2`}>
                    {items.map(item => (
                        <AddonCard
                            key={item.path}
                            addon={item}
                            selected={selected.has(item.path)}
                            onSelect={onSelect}
                            onDetails={onDetails}
                        />
                    ))}
                </div>
            )}
        </div>
    );
};

const InstalledAddonsList = ({ mods, plugins, loading, stats, scanInProgress, onDelete, onToggle, onRescan }: Props) => {
    const [tab, setTab] = useState<TabKey>('installed');
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [confirming, setConfirming] = useState(false);
    const [details, setDetails] = useState<InstalledAddon | null>(null);

    const allAddons = useMemo(() => [...mods, ...plugins], [mods, plugins]);
    const addonByPath = useMemo(() => {
        const map = new Map<string, InstalledAddon>();
        allAddons.forEach(item => map.set(item.path, item));
        return map;
    }, [allAddons]);

    useEffect(() => {
        const available = new Set(allAddons.map(a => a.path));
        setSelected(prev => new Set([...prev].filter(path => available.has(path))));
    }, [allAddons]);

    const filteredMods = mods.filter(mod => (tab === 'disabled' ? mod.disabled : !mod.disabled));
    const filteredPlugins = plugins.filter(plugin => (tab === 'disabled' ? plugin.disabled : !plugin.disabled));

    const counts = useMemo(() => {
        const disabled = stats?.disabled ?? allAddons.filter(a => a.disabled).length;
        const total = stats?.total ?? allAddons.length;

        return {
            installed: Math.max(total - disabled, 0),
            disabled,
        };
    }, [allAddons, stats]);

    const toggleSelection = (path: string) => {
        setSelected(prev => {
            const next = new Set(prev);
            next.has(path) ? next.delete(path) : next.add(path);
            return next;
        });
    };

    const selectedList = useMemo(() => [...selected], [selected]);
    const hasSelection = selectedList.length > 0;
    const selectionHasDisabled = selectedList.some(path => addonByPath.get(path)?.disabled);
    const selectionHasEnabled = selectedList.some(path => !addonByPath.get(path)?.disabled);

    const handleDelete = async () => {
        if (!selectedList.length) return;

        try {
            await onDelete(selectedList);
            setSelected(new Set());
            setConfirming(false);
        } catch (error) {
            console.error(error);
        }
    };

    const handleToggle = async (disabled: boolean) => {
        if (!selectedList.length) return;

        try {
            await onToggle(selectedList, disabled);
            setSelected(new Set());
        } catch (error) {
            console.error(error);
        }
    };

    if (loading) {
        return <Spinner size={'large'} centered />;
    }

    return (
        <div css={tw`space-y-4`}>
            <div css={tw`flex items-center justify-between`}>
                <div css={tw`flex gap-2`}>
                    <button
                        css={[
                            tw`px-3 py-1 rounded text-sm`,
                            tab === 'installed' ? tw`bg-blue-600 text-white` : tw`bg-neutral-800 text-neutral-300`,
                        ]}
                        onClick={() => setTab('installed')}
                    >
                        Installed ({counts.installed})
                    </button>
                    <button
                        css={[
                            tw`px-3 py-1 rounded text-sm`,
                            tab === 'disabled' ? tw`bg-blue-600 text-white` : tw`bg-neutral-800 text-neutral-300`,
                        ]}
                        onClick={() => setTab('disabled')}
                    >
                        Disabled ({counts.disabled})
                    </button>
                </div>

                <div css={tw`flex gap-2`}>
                    <Button.Text
                        disabled={!hasSelection || !selectionHasEnabled}
                        onClick={() => handleToggle(true)}
                        className="!text-xs"
                    >
                        Disable Selected
                    </Button.Text>
                    <Button.Text
                        disabled={!hasSelection || !selectionHasDisabled}
                        onClick={() => handleToggle(false)}
                        className="!text-xs"
                    >
                        Enable Selected
                    </Button.Text>
                    <Button.Danger disabled={!hasSelection} onClick={() => setConfirming(true)} className="!text-xs">
                        Delete Selected
                    </Button.Danger>
                    <Button.Text disabled={loading} onClick={() => onRescan()} className="!text-xs">
                        Rescan
                    </Button.Text>
                </div>
            </div>

            {scanInProgress && (
                <div css={tw`text-xs text-blue-200 bg-blue-900 bg-opacity-30 border border-blue-700 rounded px-3 py-2`}>
                    Wings is scanning addons… This may take a moment.
                </div>
            )}

            <div css={tw`space-y-6`}>
                <Section
                    title={'Mods'}
                    items={filteredMods}
                    selected={selected}
                    onSelect={toggleSelection}
                    onDetails={setDetails}
                />
                <Section
                    title={'Plugins'}
                    items={filteredPlugins}
                    selected={selected}
                    onSelect={toggleSelection}
                    onDetails={setDetails}
                />
            </div>

            <ConfirmationModal
                visible={confirming}
                title={'Delete selected files?'}
                buttonText={'Delete'}
                onConfirmed={handleDelete}
                onModalDismissed={() => setConfirming(false)}
            >
                These files will be permanently removed from the server. This cannot be undone.
            </ConfirmationModal>

            <Modal visible={!!details} onDismissed={() => setDetails(null)}>
                <div css={tw`bg-neutral-900 rounded-lg p-6 space-y-3`}>
                    <p css={tw`text-lg font-semibold text-neutral-100`}>{details?.displayName}</p>
                    <p css={tw`text-sm text-neutral-300`}>
                        Loader: {details?.loader ?? 'unknown'} | Version: {details?.version ?? 'unknown'}
                    </p>
                    <p css={tw`text-sm text-neutral-300`}>
                        {details?.description || 'Additional metadata will appear here once parsing is completed.'}
                    </p>
                    <p css={tw`text-xs text-neutral-500`}>More details coming soon.</p>
                </div>
            </Modal>
        </div>
    );
};

export default InstalledAddonsList;
