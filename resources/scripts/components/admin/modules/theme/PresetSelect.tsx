import { Dispatch, SetStateAction, useState } from 'react';
import AdminBox from '@/elements/AdminBox';
import { useStoreActions, useStoreState } from '@/state/hooks';
import { themePresets } from '@/theme/presets';
import { normalizeTheme, ThemeColorMap } from '@/theme/tokens';
import updateColors from '@/api/routes/admin/theme/updateColors';
import { Button } from '@/elements/button';
import { CheckCircleIcon } from '@heroicons/react/outline';
import classNames from 'classnames';

interface Props {
    setReload: Dispatch<SetStateAction<boolean>>;
}

const applyPresetToServer = async (colors: Partial<ThemeColorMap>) => {
    const updates = Object.entries(colors).map(([key, value]) => updateColors(key, value));
    await Promise.all(updates);
};

export default ({ setReload }: Props) => {
    const theme = useStoreState(state => state.theme.data!);
    const setTheme = useStoreActions(actions => actions.theme.setTheme);
    const [activeId, setActiveId] = useState<string | null>(null);
    const [loadingId, setLoadingId] = useState<string | null>(null);

    const isPresetActive = (colors: Partial<ThemeColorMap>) =>
        Object.entries(colors).every(([key, value]) => theme.colors[key as keyof ThemeColorMap] === value);

    const applyPreset = async (presetId: string, colors: Partial<ThemeColorMap>) => {
        if (!Object.keys(colors).length) return;

        setLoadingId(presetId);
        setReload(true);

        try {
            await applyPresetToServer(colors);
            setTheme(
                normalizeTheme({
                    ...theme,
                    colors: {
                        ...theme.colors,
                        ...colors,
                    },
                }),
            );
            setActiveId(presetId);
        } catch (error) {
            // errors will be surfaced via flash in parent if present
            console.error(error);
        } finally {
            setReload(false);
            setLoadingId(null);
        }
    };

    return (
        <AdminBox title={'Presets'} className={'h-full'}>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3 text-sm text-neutral-400">
                <span>Pick a preset to instantly apply and continue editing.</span>
                <span className="hidden sm:block text-xs text-neutral-500">One-click apply; no scrolling required.</span>
            </div>
            <div className={'grid gap-3 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5'}>
                {themePresets.map(preset => {
                    const primary = preset.colors.primary ?? theme.colors.primary;
                    const active = isPresetActive(preset.colors) || activeId === preset.id;
                    const isLoading = loadingId === preset.id;

                    return (
                        <button
                            key={preset.id}
                            type="button"
                            onClick={() => applyPreset(preset.id, preset.colors)}
                            disabled={!preset.colors.primary || isLoading}
                            className={classNames(
                                'group flex h-full flex-col items-start justify-between rounded-lg border bg-black/20 p-3 text-left transition duration-150',
                                'border-neutral-800 hover:border-neutral-500 focus:border-neutral-400 focus:outline-none',
                                active && 'border-green-600/70 shadow-[0_0_0_1px_rgba(34,197,94,0.35)]',
                                isLoading && 'opacity-70',
                            )}
                        >
                            <div className="flex w-full items-center gap-3">
                                <div
                                    className="h-9 w-9 rounded-full border border-black/30 shadow-sm"
                                    style={{ backgroundColor: primary }}
                                />
                                <div className="min-w-0">
                                    <p className="truncate font-semibold text-neutral-100">{preset.name}</p>
                                    <p className="truncate text-xs text-gray-400">
                                        {preset.description ?? 'One-click preset'}
                                    </p>
                                </div>
                                {active && <CheckCircleIcon className={'ml-auto h-5 w-5 text-green-500'} />}
                            </div>
                            <Button
                                isLoading={isLoading}
                                className={'mt-3 h-8 w-full'}
                                type={'button'}
                                size={'xsmall'}
                                disabled={!preset.colors.primary}
                                onClick={e => {
                                    e.stopPropagation();
                                    applyPreset(preset.id, preset.colors);
                                }}
                            >
                                Apply
                            </Button>
                        </button>
                    );
                })}
            </div>
        </AdminBox>
    );
};
