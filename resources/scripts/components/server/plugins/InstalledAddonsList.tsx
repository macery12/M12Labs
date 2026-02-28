import { differenceInHours, format, formatDistanceToNow } from 'date-fns';
import { InstalledAddon, InstalledAddonResponse } from '@/api/routes/server/plugins';
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
    error?: string | null;
    stats?: InstalledAddonResponse['stats'];
    scanInProgress?: boolean;
    onRescan: () => Promise<void> | void;
    onDelete: (paths: string[]) => Promise<void> | void;
    onToggle: (paths: string[], disabled: boolean) => Promise<void> | void;
    onRetry: () => Promise<void> | void;
}

type TabKey = 'installed' | 'disabled';
const LONG_LOADING_HINT_DELAY_MS = 5000;
const SKELETON_COUNT = 3;

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
    const [iconFailed, setIconFailed] = useState(false);

    useEffect(() => {
        setIconFailed(false);
    }, [addon.iconUrl, addon.iconId]);

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
                {addon.iconUrl && !iconFailed ? (
                    <img
                        src={addon.iconUrl}
                        alt={addon.displayName}
                        css={tw`w-full h-full object-cover`}
                        loading="lazy"
                        decoding="async"
                        onError={() => setIconFailed(true)}
                    />
                ) : (
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        css={tw`w-7 h-7 text-neutral-400`}
                        role="img"
                    >
                        <title>No icon available</title>
                        <path
                            fill="currentColor"
                            d="M6.5 4a2.5 2.5 0 0 0-2.45 3.01l.05.19L4.5 9h1.75A1.75 1.75 0 0 1 8 10.75v1.5A1.75 1.75 0 0 1 6.25 14H4.5l-.4 1.8a2.5 2.5 0 1 0 3.16 3.16l.09-.21L8 16.5h1.75A1.75 1.75 0 0 1 11.5 18.25v1.35l1.68.37a2.5 2.5 0 1 0 3.14-3.14l-.37-1.68H18a2 2 0 0 0 2-2v-1.75a2 2 0 0 0-2-2h-1.05l-.37-1.68A2.5 2.5 0 0 0 12 6.03l-.5.11V4.5A2.5 2.5 0 0 0 9 2.04v1.1A2.5 2.5 0 0 0 6.5 4"
                        />
                    </svg>
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

const InstalledAddonsList = ({
    mods,
    plugins,
    loading,
    error,
    stats,
    scanInProgress,
    onDelete,
    onToggle,
    onRescan,
    onRetry,
}: Props) => {
    const [tab, setTab] = useState<TabKey>('installed');
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [confirming, setConfirming] = useState(false);
    const [details, setDetails] = useState<InstalledAddon | null>(null);
    const [showLongHint, setShowLongHint] = useState(false);

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

    useEffect(() => {
        if (!loading) {
            setShowLongHint(false);
            return undefined;
        }
        const timer = window.setTimeout(() => setShowLongHint(true), LONG_LOADING_HINT_DELAY_MS);
        return () => window.clearTimeout(timer);
    }, [loading]);

    if (loading) {
        return (
            <div css={tw`space-y-4`} aria-busy="true">
                <div
                    css={tw`text-sm text-neutral-300 bg-neutral-900 border border-neutral-700 rounded p-3`}
                    role="status"
                    aria-live="polite"
                >
                    <div css={tw`flex items-center justify-between mb-2`}>
                        <span>Scanning server for installed mods/plugins…</span>
                        <div css={tw`h-1 w-24 bg-neutral-800 rounded overflow-hidden`}>
                            <div css={tw`h-full w-1/2 bg-blue-500 animate-pulse`} />
                        </div>
                    </div>
                    {showLongHint && <div css={tw`text-xs text-neutral-400`}>This can take a bit on large modpacks.</div>}
                </div>
                {Array.from({ length: SKELETON_COUNT }).map((_, index) => (
                    <div
                        key={`addon-skeleton-${index}`}
                        css={tw`border border-neutral-800 rounded-lg p-4 flex gap-4 items-start bg-neutral-900 animate-pulse`}
                        aria-hidden="true"
                    >
                        <div css={tw`w-12 h-12 rounded bg-neutral-800`} />
                        <div css={tw`flex-1 space-y-2`}>
                            <div css={tw`h-4 bg-neutral-800 rounded w-2/5`} />
                            <div css={tw`h-3 bg-neutral-800 rounded w-4/5`} />
                            <div css={tw`h-3 bg-neutral-800 rounded w-3/5`} />
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div css={tw`space-y-4`}>
            {error && (
                <div
                    css={tw`bg-red-900 bg-opacity-30 border border-red-700 text-red-100 rounded p-3 flex items-center justify-between`}
                >
                    <div>{error}</div>
                    <Button.Text aria-label="Retry loading installed addons" onClick={() => onRetry()} className="!text-xs">
                        Retry
                    </Button.Text>
                </div>
            )}
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
