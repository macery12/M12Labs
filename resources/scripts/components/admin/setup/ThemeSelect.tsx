import updateColors from '@/api/routes/admin/theme/updateColors';
import useStatus from '@/plugins/useStatus';
import { useStoreActions, useStoreState } from '@/state/hooks';
import AdminBox from '@/elements/AdminBox';
import { faCircle } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { CheckCircleIcon } from '@heroicons/react/outline';
import { normalizeTheme } from '@/theme/tokens';
import { themePresets } from '@/theme/presets';

export default ({ defaultColor }: { defaultColor: string }) => {
    const { status, setStatus } = useStatus();
    const theme = useStoreState(state => state.theme.data!);
    const setTheme = useStoreActions(actions => actions.theme.setTheme);

    const applyPreset = (presetHex: string) => {
        setStatus('loading');

        updateColors('primary', presetHex)
            .then(() => {
                setStatus('success');
                setTheme(
                    normalizeTheme({
                        ...theme,
                        colors: {
                            ...theme.colors,
                            primary: presetHex,
                        },
                    }),
                );
            })
            .catch(() => setStatus('error'));
    };

    return (
        <div>
            <div className={'mb-8 flex w-full flex-col gap-2 sm:flex-row sm:items-center'}>
                <div className={'flex flex-shrink flex-col'} style={{ minWidth: '0' }}>
                    <h2 className={'font-header text-2xl font-medium text-neutral-50'}>Theme Preferences</h2>
                    <p
                        className={
                            'hidden overflow-hidden overflow-ellipsis whitespace-nowrap text-base text-neutral-400 lg:block'
                        }
                    >
                        Select a preferred primary color for your Panel UI.
                    </p>
                </div>
            </div>
            <AdminBox status={status} title={'Set Primary Color'}>
                <div className={'grid grid-cols-2 gap-2 sm:grid-cols-4 sm:gap-4 lg:grid-cols-6 xl:grid-cols-8 lg:gap-6'}>
                    {themePresets.map(option => {
                        const primary = option.colors.primary ?? theme.colors.primary;

                        return (
                            <div
                                className={'relative text-center'}
                                key={option.id}
                                onClick={() => option.colors.primary && applyPreset(option.colors.primary)}
                            >
                                <FontAwesomeIcon
                                    icon={faCircle}
                                    style={{ color: primary, opacity: option.colors.primary ? 1 : 0.35 }}
                                    size={'3x'}
                                    className={'transition duration-300 hover:brightness-125'}
                                />
                                {option.colors.primary && defaultColor === option.colors.primary && (
                                    <div className={'absolute top-[10px] right-[27px]'}>
                                        <CheckCircleIcon className={'w-7'} />
                                    </div>
                                )}
                                <p className={'mt-1 text-xs italic text-gray-400'}>{option.name}</p>
                            </div>
                        );
                    })}
                </div>
            </AdminBox>
            <p className={'mt-2 text-right text-gray-400'}>Select a color from the options to apply it.</p>
        </div>
    );
};
