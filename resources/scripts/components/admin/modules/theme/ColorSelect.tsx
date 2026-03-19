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
    const [activeTab, setActiveTab] = useState<
        'base' | 'surfaces' | 'navigation' | 'interactive' | 'text' | 'status' | 'inputs' | 'future'
    >('base');
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const theme = useStoreState(state => state.theme.data!);
    const setTheme = useStoreActions(actions => actions.theme.setTheme);
    const normalized = useMemo(() => normalizeTheme(theme), [theme]);
    const colors = normalized.colors;
    const tokens = normalized.tokens!;

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

            <div className="flex flex-wrap gap-2 border-b border-neutral-800 pb-2 text-sm">
                <button
                    className={`rounded px-3 py-1 transition ${
                        activeTab === 'base' ? 'bg-white/10 text-neutral-50' : 'hover:bg-white/5 text-neutral-300'
                    }`}
                    onClick={() => setActiveTab('base')}
                >
                    Base
                </button>
                <button
                    className={`rounded px-3 py-1 transition ${
                        activeTab === 'surfaces' ? 'bg-white/10 text-neutral-50' : 'hover:bg-white/5 text-neutral-300'
                    }`}
                    onClick={() => setActiveTab('surfaces')}
                >
                    Surfaces
                </button>
                <button
                    className={`rounded px-3 py-1 transition ${
                        activeTab === 'navigation' ? 'bg-white/10 text-neutral-50' : 'hover:bg-white/5 text-neutral-300'
                    }`}
                    onClick={() => setActiveTab('navigation')}
                >
                    Navigation
                </button>
                <button
                    className={`rounded px-3 py-1 transition ${
                        activeTab === 'interactive' ? 'bg-white/10 text-neutral-50' : 'hover:bg-white/5 text-neutral-300'
                    }`}
                    onClick={() => setActiveTab('interactive')}
                >
                    Interactive
                </button>
                <button
                    className={`rounded px-3 py-1 transition ${
                        activeTab === 'text' ? 'bg-white/10 text-neutral-50' : 'hover:bg-white/5 text-neutral-300'
                    }`}
                    onClick={() => setActiveTab('text')}
                >
                    Text
                </button>
                <button
                    className={`rounded px-3 py-1 transition ${
                        activeTab === 'status' ? 'bg-white/10 text-neutral-50' : 'hover:bg-white/5 text-neutral-300'
                    }`}
                    onClick={() => setActiveTab('status')}
                >
                    Status
                </button>
                <button
                    className={`rounded px-3 py-1 transition ${
                        activeTab === 'inputs' ? 'bg-white/10 text-neutral-50' : 'hover:bg-white/5 text-neutral-300'
                    }`}
                    onClick={() => setActiveTab('inputs')}
                >
                    Inputs
                </button>
                <button
                    className={`rounded px-3 py-1 text-neutral-500 transition ${
                        activeTab === 'future' ? 'bg-white/5' : 'hover:bg-white/5'
                    }`}
                    onClick={() => setActiveTab('future')}
                >
                    Coming Soon
                </button>
            </div>

            <div className="pt-4">
                {activeTab === 'base' && (
                    <div className="rounded-lg border border-neutral-800 bg-black/20 p-4 space-y-3">
                        <p className="text-sm font-semibold text-neutral-100">Base</p>
                        <p className="text-xs text-neutral-400">Global background and foundation tokens.</p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {renderEditableField(
                                'background',
                                'Background',
                                'Main application background. Choose a dark tone to maintain contrast.',
                            )}
                        </div>
                    </div>
                )}

                {activeTab === 'surfaces' && (
                    <div className="rounded-lg border border-neutral-800 bg-black/20 p-4 space-y-3">
                        <p className="text-sm font-semibold text-neutral-100">Surfaces</p>
                        <p className="text-xs text-neutral-400">
                            Card, table, and header treatments for panels and sections.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {renderEditableField(
                                'secondary',
                                'Panels & Cards',
                                'Used for cards, tables, and general surfaces.',
                            )}
                            {renderEditableField(
                                'headers',
                                'Headers',
                                'Section and component headers; slightly darker than surfaces is recommended.',
                            )}
                        </div>
                    </div>
                )}

                {activeTab === 'navigation' && (
                    <div className="rounded-lg border border-neutral-800 bg-black/20 p-4 space-y-3">
                        <p className="text-sm font-semibold text-neutral-100">Navigation</p>
                        <p className="text-xs text-neutral-400">Sidebar and navigation rails.</p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {renderEditableField(
                                'sidebar',
                                'Sidebar',
                                'Base color for navigation sidebars and related rails.',
                            )}
                        </div>
                    </div>
                )}

                {activeTab === 'interactive' && (
                    <div className="rounded-lg border border-neutral-800 bg-black/20 p-4 space-y-3">
                        <p className="text-sm font-semibold text-neutral-100">Interactive</p>
                        <p className="text-xs text-neutral-400">Primary accents, buttons, and focus rings.</p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {renderEditableField(
                                'primary',
                                'Accent & Buttons',
                                'Primary call-to-action, highlights, and borders.',
                            )}
                        </div>
                    </div>
                )}

                {activeTab === 'text' && (
                    <div className="rounded-lg border border-neutral-800 bg-black/20 p-4 space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm font-semibold text-neutral-100">Text</p>
                                <p className="text-xs text-neutral-400">Derived text tokens for readability.</p>
                            </div>
                            <p className="text-xs text-neutral-500">Auto-derived</p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {renderReadOnly(
                                tokens.text.primary,
                                'Primary Text',
                                'High-contrast text on dark backgrounds.',
                            )}
                            {renderReadOnly(tokens.text.muted, 'Muted Text', 'Secondary/helper text tone.')}
                        </div>
                    </div>
                )}

                {activeTab === 'status' && (
                    <div className="rounded-lg border border-neutral-800 bg-black/20 p-4 space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm font-semibold text-neutral-100">Status</p>
                                <p className="text-xs text-neutral-400">Semantic feedback colors.</p>
                            </div>
                            <p className="text-xs text-neutral-500">Auto-derived</p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {renderReadOnly(tokens.status.success, 'Success', 'Applied to success states.')}
                            {renderReadOnly(tokens.status.danger, 'Danger', 'Applied to error states.')}
                        </div>
                    </div>
                )}

                {activeTab === 'inputs' && (
                    <div className="rounded-lg border border-neutral-800 bg-black/20 p-4 space-y-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm font-semibold text-neutral-100">Inputs</p>
                                <p className="text-xs text-neutral-400">Fields, outlines, and focus states.</p>
                            </div>
                            <p className="text-xs text-neutral-500">Auto-derived</p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {renderReadOnly(tokens.inputs.surface, 'Field Surface', 'Input backgrounds.')}
                            {renderReadOnly(tokens.inputs.focus, 'Focus', 'Outline color when focused.')}
                        </div>
                    </div>
                )}

                {activeTab === 'future' && (
                    <div className="rounded-lg border border-dashed border-neutral-800 bg-black/10 p-4 text-sm text-neutral-400">
                        Additional granular tokens (borders, charts, banners) will appear here in a future update.
                    </div>
                )}
            </div>
        </AdminBox>
    );
};
