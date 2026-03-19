import { useState } from 'react';
import useFlash from '@/plugins/useFlash';
import { Button } from '@/elements/button';
import { Dialog } from '@/elements/dialog';
import Preview from '@admin/modules/theme/Preview';
import AdminContentBlock from '@/elements/AdminContentBlock';
import ColorSelect from '@admin/modules/theme/ColorSelect';
import resetTheme from '@/api/routes/admin/theme/resetTheme';
import PresetSelect from '@admin/modules/theme/PresetSelect';
import classNames from 'classnames';

export default () => {
    const [reload, setReload] = useState<boolean>(false);
    const [visible, setVisible] = useState<boolean>(false);
    const [activeMobilePane, setActiveMobilePane] = useState<'editor' | 'preview'>('editor');
    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const submit = () => {
        clearFlashes('theme:colors');

        resetTheme()
            .then(() => {
                // @ts-expect-error this is fine
                window.location = '/admin/theme';
            })
            .catch(error => {
                clearAndAddHttpError({ key: 'theme:colors', error });
            });
    };

    return (
        <AdminContentBlock showFlashKey={'theme:colors'}>
            <Dialog.Confirm
                title={'Are you sure?'}
                open={visible}
                onClose={() => setVisible(false)}
                onConfirmed={submit}
            >
                Performing this action will immediately wipe all of your custom theming settings. Only do this if you
                wish to return to the stock appearance of Jexactyl. This action cannot be reversed.
            </Dialog.Confirm>

            <div className="space-y-6">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <div className="flex min-w-0 flex-1 flex-col">
                        <h2 className="font-header text-2xl font-medium text-neutral-50">Theme Workspace</h2>
                        <p className="text-sm text-neutral-400">
                            Apply presets, tweak tokens, and preview live without losing your place.
                        </p>
                    </div>
                    <div className="flex gap-2 lg:ml-auto">
                        <Button
                            type={'button'}
                            size={Button.Sizes.Medium}
                            onClick={() => setVisible(true)}
                            className={'h-10 whitespace-nowrap px-4 py-0'}
                        >
                            Reset to Defaults
                        </Button>
                    </div>
                </div>

                <PresetSelect setReload={setReload} />

                <div className="flex items-center gap-2 xl:hidden">
                    <Button
                        type="button"
                        size={Button.Sizes.Small}
                        onClick={() => setActiveMobilePane('editor')}
                        className={classNames(
                            'h-9 px-3',
                            activeMobilePane === 'editor' && 'bg-primary-500/20 text-neutral-50',
                        )}
                    >
                        Editor
                    </Button>
                    <Button
                        type="button"
                        size={Button.Sizes.Small}
                        onClick={() => setActiveMobilePane('preview')}
                        className={classNames(
                            'h-9 px-3',
                            activeMobilePane === 'preview' && 'bg-primary-500/20 text-neutral-50',
                        )}
                    >
                        Preview
                    </Button>
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(420px,520px)] 2xl:grid-cols-[minmax(0,1fr)_minmax(500px,560px)]">
                    <div
                        className={classNames(
                            'min-w-0',
                            'transition-opacity',
                            activeMobilePane !== 'editor' && 'hidden xl:block',
                        )}
                    >
                        <ColorSelect setReload={setReload} className={'h-full'} />
                    </div>

                    <div
                        className={classNames(
                            'min-w-0 xl:sticky xl:top-6',
                            activeMobilePane !== 'preview' && 'hidden xl:block',
                        )}
                    >
                        <Preview reload={reload} />
                    </div>
                </div>
            </div>
        </AdminContentBlock>
    );
};
