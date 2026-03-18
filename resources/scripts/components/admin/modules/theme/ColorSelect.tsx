import { Dispatch, SetStateAction, useMemo, useState } from 'react';
import useFlash from '@/plugins/useFlash';
import Label from '@/elements/Label';
import Input from '@/elements/Input';
import AdminBox from '@/elements/AdminBox';
import Spinner from '@/elements/Spinner';
import updateColors from '@/api/routes/admin/theme/updateColors';
import { CheckCircleIcon } from '@heroicons/react/outline';
import { useStoreActions, useStoreState } from '@/state/hooks';
import FlashMessageRender from '@/elements/FlashMessageRender';
import { faPaintbrush } from '@fortawesome/free-solid-svg-icons';
import { normalizeTheme } from '@/theme/tokens';

interface Props {
    setReload: Dispatch<SetStateAction<boolean>>;
    className?: string;
}

export default ({ setReload, className }: Props) => {
    const [loading, setLoading] = useState<boolean>(false);
    const [success, setSuccess] = useState<boolean>(false);
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const theme = useStoreState(state => state.theme.data!);
    const setTheme = useStoreActions(actions => actions.theme.setTheme);
    const normalized = useMemo(() => normalizeTheme(theme), [theme]);
    const colors = normalized.colors;

    const update = async (key: string, value: string) => {
        clearFlashes();
        setReload(true);
        setLoading(true);
        setSuccess(false);

        setTheme(
            normalizeTheme({
                ...theme,
                colors: {
                    primary: key === 'primary' ? value : colors.primary,
                    secondary: key === 'secondary' ? value : colors.secondary,

                    background: key === 'background' ? value : colors.background,
                    headers: key === 'headers' ? value : colors.headers,
                    sidebar: key === 'sidebar' ? value : colors.sidebar,
                },
            }),
        );

        updateColors(key, value)
            .then(() => {
                setReload(false);
                setSuccess(true);
                setLoading(false);

                setTimeout(() => setSuccess(false), 2000);
            })
            .catch(error => {
                clearAndAddHttpError({ key: 'auth:modules:discord', error });

                setLoading(false);
            });
    };

    const renderEditableField = (key: string, label: string, description: string) => (
        <div key={key} className={'space-y-1'}>
            <div className={'flex items-center justify-between gap-3'}>
                <Label className={'mb-0'}>{label}</Label>
                <Input
                    id={key}
                    type={'color'}
                    name={key}
                    value={(colors as Record<string, string>)[key]}
                    onChange={e => update(key, e.target.value)}
                    className={'h-10 w-20 cursor-pointer p-1'}
                />
            </div>
            <p className={'text-xs text-gray-400'}>{description}</p>
        </div>
    );

    const renderReadOnly = (value: string, label: string, description: string) => (
        <div key={label} className={'flex items-center gap-3 rounded border border-neutral-800 bg-black/20 p-3'}>
            <div className={'h-10 w-10 rounded'} style={{ backgroundColor: value }} />
            <div>
                <p className={'text-sm font-semibold text-neutral-200'}>{label}</p>
                <p className={'text-xs text-gray-400'}>{description}</p>
            </div>
        </div>
    );

    return (
        <AdminBox title={'Theme Controls'} icon={faPaintbrush} className={className ?? 'h-full'}>
            <FlashMessageRender byKey={'theme:colors'} className={'my-2'} />
            {loading && <Spinner className={'absolute top-0 right-0 m-3.5'} size={'small'} />}
            {success && <CheckCircleIcon className={'absolute top-0 right-0 m-3.5 h-5 w-5 text-green-500'} />}

            <div className={'space-y-6'}>
                <div className={'grid gap-4 sm:grid-cols-2'}>
                    <div className={'rounded-lg border border-neutral-800 bg-black/20 p-4'}>
                        <p className={'mb-3 text-sm font-semibold text-neutral-100'}>Base</p>
                        {renderEditableField(
                            'background',
                            'Background',
                            'Main application background. Choose a dark tone to maintain contrast.',
                        )}
                    </div>
                    <div className={'rounded-lg border border-neutral-800 bg-black/20 p-4'}>
                        <p className={'mb-3 text-sm font-semibold text-neutral-100'}>Surfaces</p>
                        <div className={'space-y-3'}>
                            {renderEditableField(
                                'secondary',
                                'Panels & Cards',
                                'Used for cards, tables, and general surfaces.',
                            )}
                            {renderEditableField(
                                'headers',
                                'Headers',
                                'Used for component and section headers. Slightly darker than surfaces is recommended.',
                            )}
                        </div>
                    </div>
                </div>

                <div className={'grid gap-4 sm:grid-cols-2'}>
                    <div className={'rounded-lg border border-neutral-800 bg-black/20 p-4'}>
                        <p className={'mb-3 text-sm font-semibold text-neutral-100'}>Navigation</p>
                        {renderEditableField(
                            'sidebar',
                            'Sidebar',
                            'Color for navigation sidebars and their base background.',
                        )}
                    </div>
                    <div className={'rounded-lg border border-neutral-800 bg-black/20 p-4'}>
                        <p className={'mb-3 text-sm font-semibold text-neutral-100'}>Interactive</p>
                        {renderEditableField(
                            'primary',
                            'Accent & Buttons',
                            'Accent color for primary actions, highlights, and borders.',
                        )}
                    </div>
                </div>

                <div className={'grid gap-4 lg:grid-cols-3'}>
                    <div className={'rounded-lg border border-neutral-800 bg-black/20 p-4'}>
                        <p className={'mb-3 text-sm font-semibold text-neutral-100'}>Text</p>
                        <div className={'space-y-3'}>
                            {renderReadOnly(
                                normalized.tokens.text.primary,
                                'Primary Text',
                                'Derived for high-contrast text on dark backgrounds.',
                            )}
                            {renderReadOnly(
                                normalized.tokens.text.muted,
                                'Muted Text',
                                'Used for secondary or helper text.',
                            )}
                        </div>
                    </div>
                    <div className={'rounded-lg border border-neutral-800 bg-black/20 p-4'}>
                        <p className={'mb-3 text-sm font-semibold text-neutral-100'}>Status</p>
                        <div className={'space-y-3'}>
                            {renderReadOnly(normalized.tokens.status.success, 'Success', 'Applied to success states.')}
                            {renderReadOnly(normalized.tokens.status.danger, 'Danger', 'Applied to error states.')}
                        </div>
                    </div>
                    <div className={'rounded-lg border border-neutral-800 bg-black/20 p-4'}>
                        <p className={'mb-3 text-sm font-semibold text-neutral-100'}>Inputs</p>
                        <div className={'space-y-3'}>
                            {renderReadOnly(normalized.tokens.inputs.surface, 'Field Surface', 'Input backgrounds.')}
                            {renderReadOnly(normalized.tokens.inputs.focus, 'Focus', 'Outline color when focused.')}
                        </div>
                    </div>
                </div>
            </div>
        </AdminBox>
    );
};
