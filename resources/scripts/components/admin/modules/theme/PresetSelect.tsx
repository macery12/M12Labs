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
            <div className={'grid gap-3 sm:grid-cols-2'}>
                {themePresets.map(preset => {
                    const primary = preset.colors.primary ?? theme.colors.primary;
                    const active = isPresetActive(preset.colors) || activeId === preset.id;
                    const isLoading = loadingId === preset.id;

                    return (
                        <div
                            key={preset.id}
                            className={classNames(
                                'flex flex-col justify-between rounded border border-neutral-800 bg-black/20 p-3 transition duration-200',
                                active && 'border-green-600/70',
                            )}
                        >
                            <div className={'flex items-center justify-between'}>
                                <div className={'flex items-center gap-3'}>
                                    <div
                                        className={'h-10 w-10 rounded-full border border-black/40 shadow'}
                                        style={{ backgroundColor: primary }}
                                    />
                                    <div>
                                        <p className={'font-semibold text-neutral-100'}>{preset.name}</p>
                                        <p className={'text-xs text-gray-400'}>
                                            {preset.description ?? 'One-click apply preset'}
                                        </p>
                                    </div>
                                </div>
                                {active && <CheckCircleIcon className={'h-5 w-5 text-green-500'} />}
                            </div>
                            <Button
                                isLoading={isLoading}
                                className={'mt-3 h-9 w-full'}
                                type={'button'}
                                size={'xsmall'}
                                onClick={() => applyPreset(preset.id, preset.colors)}
                                disabled={!preset.colors.primary}
                            >
                                Apply
                            </Button>
                        </div>
                    );
                })}
            </div>
        </AdminBox>
    );
};
