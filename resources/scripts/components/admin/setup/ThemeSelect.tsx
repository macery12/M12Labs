import updateColors from '@/api/routes/admin/theme/updateColors';
import useStatus from '@/plugins/useStatus';
import { useStoreActions, useStoreState } from '@/state/hooks';
import AdminBox from '@/elements/AdminBox';
import { faCircle } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { CheckCircleIcon } from '@heroicons/react/outline';

const colorOptions = [
    { hex: '#16a34a', name: 'Jexactyl Green' },
    { hex: '#12aaaa', name: 'Microsoft Teal' },
    { hex: '#ff0000', name: 'Brick Red' },
    { hex: '#9D00FF', name: 'Iris Purple' },
    { hex: '#FFA500', name: 'Orange Orange' },
    { hex: '#32559f', name: 'Ptero Blue' },
    { hex: '#ff99c8', name: 'Pretty Pink' },
    { hex: '#5e6472', name: 'Plain Grey' },
];

export default ({ defaultColor }: { defaultColor: string }) => {
    const { status, setStatus } = useStatus();
    const theme = useStoreState(state => state.theme.data!);
    const setTheme = useStoreActions(actions => actions.theme.setTheme);

    const changeColor = (hex: string) => {
        setStatus('loading');

        updateColors('primary', hex).then(() => {
            setStatus('success');
            setTheme({
                ...theme,
                colors: {
                    ...theme.colors,
                    primary: hex,
                },
            });
        });
    };

    return (
        <div>
            <div className={'mb-8 flex w-full flex-row items-center'}>
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
                <div className={'grid grid-cols-4 gap-4 lg:grid-cols-8 lg:gap-8'}>
                    {colorOptions.map(option => (
                        <div
                            className={'relative text-center'}
                            key={option.hex}
                            onClick={() => changeColor(option.hex)}
                        >
                            <FontAwesomeIcon
                                icon={faCircle}
                                style={{ color: option.hex }}
                                size={'3x'}
                                className={'transition duration-300 hover:brightness-125'}
                            />
                            {defaultColor === option.hex && (
                                <div className={'absolute top-[10px] right-[27px]'}>
                                    <CheckCircleIcon className={'w-7'} />
                                </div>
                            )}
                            <p className={'mt-1 text-xs italic text-gray-400'}>{option.name}</p>
                        </div>
                    ))}
                </div>
            </AdminBox>
            <p className={'mt-2 text-right text-gray-400'}>Select a color from the options to apply it.</p>
        </div>
    );
};
